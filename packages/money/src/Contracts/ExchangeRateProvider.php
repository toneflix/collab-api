<?php

namespace Toneflix\Money\Contracts;

use Toneflix\Money\Exceptions\ExchangeException;

/**
 * A source of exchange rates.
 */
interface ExchangeRateProvider
{
    /**
     * How much one unit of `$source` is worth in `$target`.
     *
     * @throws ExchangeException
     */
    public function rate(string $source, string $target): float;

    /**
     * `$amount` of `$source` in `$target`.
     *
     * @throws ExchangeException
     */
    public function convert(float $amount, string $source, string $target): float;
}
