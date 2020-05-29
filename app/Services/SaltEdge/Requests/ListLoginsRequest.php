<?php

namespace App\Services\SaltEdge\Requests;

use App\Repositories\SaltEdge\CustomerRepository;
use App\Services\SaltEdge\Objects\Login;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ListLoginsRequest extends SaltEdgeRequest
{
    /**
     * @var Login
     */
    private $logins;

    protected $uri;

    /**
     * ListLoginsRequest constructor.
     *
     * From the official documentation: "The central entity of the API, representing a connection between a customer’s bank and Salt Edge."
     * Basically this tells how much bank accounts are linked on Salt Edge. Expect to get more connections later on
     * @TODO: build-in a loop mechanism to handle more logins/connections.
     */
    public function __construct()
    {
        parent::__construct();

        $this->uri = 'logins';
    }

    public function call(): void
    {
        $response = $this->getRequest($this->uri);

        if (null === $response) {
            Log::error('Could not continue processing. Please see the error logs for further details.');
            return;
        }

        if (!isset($response['body']['data'])) {
            Log::error('The data structure returned seems unrecognized.');
            return;
        }

        $collection = new Collection;
        foreach ($response['body']['data'] as $loginArray) {
            $collection->push(new Login($loginArray));
        }

        // @TODO: Maybe add some sorting later on?
        // @TODO: for sure make it loop, on the request if I intend to get more connections
        foreach ($collection as $k => $c) {
            $customer = new CustomerRepository;
            $customer = $customer->findByCustomerId($c->getCustomerId());

            // No customer found, create a new entry
            if (null === $customer) {
                $customer = new CustomerRepository;
                $customer->store($c);
                continue;
            }

            $customer->object = serialize($c);
            $customer->hash = hash('sha256', serialize($c));
            $customer->save();
        }

        $this->logins = $collection->toArray();
    }

    /**
     * @return Login|null
     */
    public function getLogins(): ?Login
    {
        return $this->logins;
    }
}