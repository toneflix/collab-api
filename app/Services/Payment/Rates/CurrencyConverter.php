<?php

namespace App\Services\Payment\Rates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Toneflix\Money\Contracts\ExchangeRateProvider;
use Toneflix\Money\Exceptions\ExchangeException;
use Toneflix\Money\Providers\ExchangeRateApi;
use Toneflix\Money\Providers\FrankfurterApi;

/**
 * Live exchange rates through toneflix/money, cached for a few hours.
 */
class CurrencyConverter
{
    public const CACHE_HOURS = 6;

    public const PROVIDERS = [
        'exchangerate-api' => ExchangeRateApi::class,
        'frankfurter' => FrankfurterApi::class,
    ];

    /**
     * How much one unit of `$from` is worth in `$to`.
     */
    public function rate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $key = "exchange-rates:{$this->providerName()}:{$from}:{$to}";

        if ($rate = Cache::get($key)) {
            return (float) $rate;
        }

        try {
            $rate = $this
                ->provider()
                ->rate($from, $to);
        } catch (ExchangeException $e) {
            report($e);

            throw ValidationException::withMessages(['currency' => "Payments in {$to} are not available right now."]);
        }

        if ($rate <= 0) {
            throw ValidationException::withMessages(['currency' => "Payments in {$to} are not available right now."]);
        }

        Cache::put($key, $rate, now()->addHours(self::CACHE_HOURS));

        return $rate;
    }

    public function provider(): ExchangeRateProvider
    {
        $class = self::PROVIDERS[$this->providerName()] ?? ExchangeRateApi::class;

        return new $class(config('services.exchange_rates.key') ?: null, new LaravelHttpClient);
    }

    protected function providerName(): string
    {
        return (string) config('services.exchange_rates.provider', 'exchangerate-api');
    }
}
