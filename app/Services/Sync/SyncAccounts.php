<?php

namespace App\Services\Sync;

use App\Repositories\Firefly\AccountRepository;
use App\Repositories\Firefly\AccountRepository as FFRepo;
use App\Repositories\SaltEdge\AccountRepository as SARepo;
use App\Services\Firefly\Objects\Account as ffAccount;
use App\Services\Firefly\Requests\Accounts;
use App\Services\SaltEdge\Objects\Account as saAccount;
use App\Services\SaltEdge\Objects\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncAccounts extends SyncHandler
{
    private $uri;

    /**
     * SyncAccounts constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->uri = 'accounts';
    }

    public function call(): void
    {
        Log::info('Fetching both Firefly and SaltEdge accounts.');
        $saltEdgeAccounts = new SARepo;
        $saltEdgeAccounts = $saltEdgeAccounts->findAllAccounts();

        // Alright, now the mappings needs to happen
        // The basic idea is that the IBAN is unique and should be searched for
        foreach ($saltEdgeAccounts as $s) {
            $object = unserialize(decrypt($s->object));
            // Try to find a matching Firefly accounts
            $fireflyAccount = new FFRepo;

            // @TODO: should also be able to store the account_id in Firefly's meta table for future reference
            // Downside would be that the connection ID's change, the meta data would also render uselsess. The iban seems to be the safest solution as far as I see now.
            $fireflyAccountByIban = $fireflyAccount->findByIban($s->account_name);

            if (null === $fireflyAccountByIban) {
                Log::info(sprintf('Firefly account with details %s was not found by Iban. Continue search on account number.', $s->account_name));
                $fireflyAccountByAccountNumber = $fireflyAccount->findByAccountNumber($s->account_name);
                if (null === $fireflyAccountByAccountNumber) {
                    Log::info(sprintf('Firefly account with details %s was not found by AccountNumber. Perhaps it is time to create one.', $s->account_name));
                    $newAccount = $this->createAccount($object);
                    if (null === $newAccount) {
                        Log::error(sprintf('Unable to create account for %s. Skipping record and continuing...', $s->account_name));
                    }
                }
            }
            Log::info(sprintf('Account with details %s already exists. Skipping...', $s->account_name));
        }
    }

    /**
     * @param saAccount $saltEdgeAccount
     * @return bool|null
     * @throws \Exception
     */
    public function createAccount(saAccount $saltEdgeAccount): ?bool
    {
        /**
         * "name": "My checking account",
         * "type": "asset",
         * "iban": "GB98MIDL07009312345678",
         * "bic": "BOFAUS3N",
         * "account_number": "7009312345678",
         * "opening_balance": -1012.12,
         * "opening_balance_date": "2018-09-17",
         * "virtual_balance": 1000,
         * "currency_id": 12,
         * "currency_code": "EUR",
         * "active": true,
         * "include_net_worth": true,
         * "account_role": "defaultAsset",
         * "credit_card_type": "monthlyFull",
         * "monthly_payment_date": "2018-09-17",
         * "liability_type": "loan",
         * "liability_amount": 12000,
         * "liability_start_date": "2017-09-17",
         * "interest": "5.3",
         * "interest_period": "monthly",
         * "notes": "Some example notes"
         */

        $accountType = $this->determineFFAccountType($saltEdgeAccount->getNature());

        // @TODO: nasty, make objects later on
        // First want to see how corresponding the objects can be made based on the initial assets, etc. accounts from SaltEdge and the accounts needed for transactions
        $data = [];
        $data['name'] = $saltEdgeAccount->getExtra()['account_name'];
        $data['type'] = $accountType;

        if ($accountType === 'liability') {
            $data['liability_type'] = 'mortgage'; // Fixed value, in my case. Bring on better logic handling later on.
            $data['liability_amount'] = abs($saltEdgeAccount->getBalance());
            $data['liability_start_date'] = new Carbon('2020-01-01');

            // Now here is a tricky part. If a person has multiple mortgages structure (quite common for instance in the Netherlands), SaltEdge will provide interest rates per mortgage
            // How to deal with that? I have not yet found a solution, whereas the total sum on mortgage is shown and there doesn't seem to be a division per mortgage.
            // Quickly and dirty for now: take the highest: it might feel beneficial if you 'save' some money if you pay less mortgage? ;)
            $data['interest'] = $saltEdgeAccount->getExtra()['floating_interest_rate']['max_value'];
            $data['interest_period'] = 'monthly'; // Fixed value
            $data['account_number'] = $saltEdgeAccount->getName();
        } else {
            $data['iban'] = $saltEdgeAccount->getName();
            // @TODO: Default for now, make proper code later on
            if ($saltEdgeAccount->getNature() == 'account') {
                $data['account_role'] = 'defaultAsset';
            } else {
                $data['account_role'] = 'savingAsset';
            }
            $data['opening_balance'] = $saltEdgeAccount->getBalance();
            $data['opening_balance_date'] = Carbon::now();
        }

        $postRequest = new Accounts();
        $postRequest->postRequest($this->uri, $data);

        return true;
    }

    /**
     * @param Transaction $saltEdgeTransaction
     * @return ffAccount|null
     * @throws \Exception
     */
    public function createAccountTransaction(Transaction $saltEdgeTransaction): ?ffAccount
    {
        Log::debug(sprintf('Starting the creation process for account %s.', $saltEdgeTransaction->getDescription()));
        $data = [];
        $data['name'] = $saltEdgeTransaction->getDescription();

        // @TODO: make some nice constants or something for this!
        $data['type'] = 'expense';
        if (1 === bccomp($saltEdgeTransaction->getAmount(), 0)) {
            $data['type'] = 'revenue';
        }

        $postRequest = new Accounts();
        $postRequest = $postRequest->postRequest($this->uri, $data);

        if (null === $postRequest) {
            Log::error('Error creating new account for transactions.');
            return null;
        }

        $newAccount = new ffAccount($postRequest['body']['data']);

        // Store the newly made account, so it can be used in the next iteration(s)
        $store = new AccountRepository;
        $store->store($newAccount);

        Log::debug(sprintf('Finished creating account. New ID returned: %s', $newAccount->getId()));

        return $newAccount;
    }
}