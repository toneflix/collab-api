<?php

namespace App\Services\Payment;

use Yabacon\Paystack\Contracts\RouteInterface;

/**
 * The Paystack endpoints payouts use: banks, account lookup, recipients and transfers.
 */
class PaystackTransfers implements RouteInterface
{
    public static function root()
    {
        return '';
    }

    public static function banks()
    {
        return [
            RouteInterface::METHOD_KEY => RouteInterface::GET_METHOD,
            RouteInterface::ENDPOINT_KEY => '/bank',
            RouteInterface::PARAMS_KEY => ['currency', 'perPage', 'use_cursor', 'next'],
        ];
    }

    public static function resolveAccount()
    {
        return [
            RouteInterface::METHOD_KEY => RouteInterface::GET_METHOD,
            RouteInterface::ENDPOINT_KEY => '/bank/resolve',
            RouteInterface::PARAMS_KEY => ['account_number', 'bank_code'],
        ];
    }

    public static function createRecipient()
    {
        return [
            RouteInterface::METHOD_KEY => RouteInterface::POST_METHOD,
            RouteInterface::ENDPOINT_KEY => '/transferrecipient',
            RouteInterface::PARAMS_KEY => ['type', 'name', 'account_number', 'bank_code', 'currency'],
        ];
    }

    public static function initiate()
    {
        return [
            RouteInterface::METHOD_KEY => RouteInterface::POST_METHOD,
            RouteInterface::ENDPOINT_KEY => '/transfer',
            RouteInterface::PARAMS_KEY => ['source', 'amount', 'currency', 'recipient', 'reference', 'reason'],
        ];
    }

    public static function verify()
    {
        return [
            RouteInterface::METHOD_KEY => RouteInterface::GET_METHOD,
            RouteInterface::ENDPOINT_KEY => '/transfer/verify/{reference}',
            RouteInterface::ARGS_KEY => ['reference'],
        ];
    }
}
