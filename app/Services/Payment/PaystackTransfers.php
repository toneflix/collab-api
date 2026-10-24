<?php

namespace App\Services\Payment;

use Toneflix\Paystack\Resources\AbstractResource;

/**
 * The Paystack endpoints payouts use: banks, account checks, recipients and transfers.
 */
class PaystackTransfers extends AbstractResource
{
    /**
     * @param  array<string, mixed>  $query
     */
    public function banks(array $query): object
    {
        return $this->client->request('GET', '/bank', $query);
    }

    /**
     * @param  array{account_number: string, bank_code: string}  $query
     */
    public function resolveAccount(array $query): object
    {
        return $this->client->request('GET', '/bank/resolve', $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validateAccount(array $payload): object
    {
        return $this->client->request('POST', '/bank/validate', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRecipient(array $payload): object
    {
        return $this->client->transferrecipient->create($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function initiate(array $payload): object
    {
        return $this->client->transfer->initiate($payload);
    }

    /**
     * @param  array{reference: string}  $payload
     */
    public function verify(array $payload): object
    {
        return $this->client->request('GET', '/transfer/verify/'.rawurlencode($payload['reference']));
    }
}
