<?php

namespace Toneflix\Money\Providers;

use Toneflix\Money\Contracts\ExchangeRateProvider;
use Toneflix\Money\Contracts\HttpClient;
use Toneflix\Money\Exceptions\ExchangeException;
use Toneflix\Money\Http\StreamHttpClient;

/**
 * Rates from Frankfurter (https://frankfurter.dev), which needs no API key.
 */
class FrankfurterApi implements ExchangeRateProvider
{
    protected const API = 'https://api.frankfurter.dev/v2/rate';

    protected HttpClient $http;

    public function __construct(?string $apiKey = null, ?HttpClient $http = null)
    {
        $this->http = $http ?? new StreamHttpClient;
    }

    public function rate(string $source, string $target): float
    {
        $data = $this->http->getJson(sprintf('%s/%s/%s', self::API, strtoupper($source), strtoupper($target)));
        $rate = $data['rate'] ?? null;

        if (is_numeric($rate) && is_finite((float) $rate)) {
            return (float) $rate;
        }

        throw new ExchangeException((string) ($data['error-type'] ?? $data['message'] ?? 'An error occurred while fetching exchange rate'), static::class);
    }

    public function convert(float $amount, string $source, string $target): float
    {
        return $this->rate($source, $target) * $amount;
    }
}
