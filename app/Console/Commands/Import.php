<?php

namespace App\Console\Commands;

use App\Services\Fireflly\Requests\Transactions;
use App\Services\Firefly\Requests\Accounts;
use App\Services\SaltEdge\Requests\ListAccountsRequest;
use App\Services\SaltEdge\Requests\ListLoginsRequest;
use App\Services\SaltEdge\Requests\ListTransactions;
use App\Services\Sync\SyncAccounts;
use App\Services\Sync\SyncTransactions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Import extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        /**
         * @NOTE
         * The main idea (for now), is that these calls are decoupled on purpose.
         * Future refactoring should prove this can be handled via a queue/job
         * and I intend to give them a sole purpose and not be depended on the workflow
         * (since this might change later on).
         */

        $startTime = microtime(true);
        $this->line('Starting import run');

        // Yes, not good, but so easy for development
        //goto Sync;
        //goto Transactions;
        Log::info('Starting SaltEdge ListLoginsRequest');
        $saltEdgeLogins = app(ListLoginsRequest::class);
        $saltEdgeLogins->call();
        Log::info('Finished SaltEdge ListLoginsRequest');

        Sync:
        Log::info('Starting SaltEdge ListAccountsRequest');
        $saltEdgeAccounts = app(ListAccountsRequest::class);
        $saltEdgeAccounts->call();
        Log::info('Finished SaltEdge ListAccountsRequest');

        Log::info('Starting FireFly AccountsRequest');
        $fireflyAccounts = app(Accounts::class);
        $fireflyAccounts->call();
        Log::info('Starting FireFly AccountsRequest');

        Log::info("Starting to synchronize accounts.");
        $syncAccounts = app(SyncAccounts::class);
        $syncAccounts->call();
        Log::info("Finished synchronize accounts.");

        Transactions:
        Log::info('Starting SaltEdge ListTransactionsRequest');
        $saltEdgeTransactions = app(ListTransactions::class);
        $saltEdgeTransactions->call();
        Log::info('Finished SaltEdge ListTransactionsRequest');

        Log::info('Starting Firefly TransactionsRequest');
        $fireflyTransactions = app(Transactions::class);
        $fireflyTransactions->call();
        Log::info('Finished Firefly TransactionsRequest');

        Log::info("Starting to synchronize transactions.");
        $syncTransactions = app(SyncTransactions::class);
        $syncTransactions->call();
        Log::info("Finished synchronize accounts.");

        $endTime = round(microtime(true) - $startTime, 4);
        $this->comment(sprintf('Finished the test in %s second(s).', $endTime));
    }
}
