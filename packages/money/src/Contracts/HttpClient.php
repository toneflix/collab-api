<?php

namespace Toneflix\Money\Contracts;

/**
 * Fetches JSON for the exchange rate providers. Swap it to use your framework's
 * HTTP client, or a fake in tests.
 */
interface HttpClient
{
    /**
     * @return array<string, mixed>
     */
    public function getJson(string $url): array;
}
