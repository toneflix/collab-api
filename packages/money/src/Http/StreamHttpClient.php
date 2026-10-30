<?php

namespace Toneflix\Money\Http;

use Toneflix\Money\Contracts\HttpClient;
use Toneflix\Money\Exceptions\ExchangeException;

/**
 * Fetches JSON with PHP's own streams, so the package needs no dependencies.
 */
class StreamHttpClient implements HttpClient
{
    public function __construct(protected int $timeout = 15) {}

    public function getJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new ExchangeException('Could not reach the exchange rate provider.');
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ExchangeException('The exchange rate provider returned an invalid response.');
        }

        return $data;
    }
}
