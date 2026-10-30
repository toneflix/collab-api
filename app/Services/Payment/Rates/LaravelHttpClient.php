<?php

namespace App\Services\Payment\Rates;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Toneflix\Money\Contracts\HttpClient;
use Toneflix\Money\Exceptions\ExchangeException;

/**
 * Sends the exchange rate requests of toneflix/money through Laravel's HTTP client.
 */
class LaravelHttpClient implements HttpClient
{
    public function getJson(string $url): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new ExchangeException('Could not reach the exchange rate provider.', previous: $e);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new ExchangeException('The exchange rate provider returned an invalid response.');
        }

        return $data;
    }
}
