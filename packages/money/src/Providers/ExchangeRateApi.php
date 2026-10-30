<?php

namespace Toneflix\Money\Providers;

use Toneflix\Money\Contracts\ExchangeRateProvider;
use Toneflix\Money\Contracts\HttpClient;
use Toneflix\Money\Exceptions\ExchangeException;
use Toneflix\Money\Http\StreamHttpClient;

/**
 * Rates from ExchangeRate-API (https://www.exchangerate-api.com), which needs an API key.
 * The key falls back to the EXCHANGERATE_API_KEY environment variable.
 */
class ExchangeRateApi implements ExchangeRateProvider
{
    protected const API = 'https://v6.exchangerate-api.com/v6';

    protected HttpClient $http;

    public function __construct(protected ?string $apiKey = null, ?HttpClient $http = null)
    {
        $this->http = $http ?? new StreamHttpClient;
    }

    public function rate(string $source, string $target): float
    {
        return (float) $this->request($source, $target, 1)['conversion_rate'];
    }

    public function convert(float $amount, string $source, string $target): float
    {
        return (float) $this->request($source, $target, $amount)['conversion_result'];
    }

    /**
     * @return array{conversion_rate: float, conversion_result: float}
     */
    protected function request(string $source, string $target, float $amount): array
    {
        $key = $this->apiKey ?: (getenv('EXCHANGERATE_API_KEY') ?: '');

        if ($key === '') {
            throw new ExchangeException('missing-key', static::class);
        }

        $url = sprintf('%s/%s/pair/%s/%s/%s', self::API, rawurlencode($key), strtoupper($source), strtoupper($target), $amount);
        $data = $this->http->getJson($url);

        if (($data['result'] ?? null) === 'success') {
            return [
                'conversion_rate' => (float) $data['conversion_rate'],
                'conversion_result' => (float) $data['conversion_result'],
            ];
        }

        throw new ExchangeException((string) ($data['error-type'] ?? 'An error occurred while fetching exchange rate'), static::class);
    }
}
