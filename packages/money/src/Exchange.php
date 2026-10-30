<?php

namespace Toneflix\Money;

use Toneflix\Money\Contracts\ExchangeRateProvider;
use Toneflix\Money\Contracts\HttpClient;
use Toneflix\Money\Exceptions\ExchangeException;
use Toneflix\Money\Providers\ExchangeRateApi;

/**
 * Converts amounts between currencies through the configured rate provider.
 *
 * @method static static from(string $currency)
 * @method static static to(string $currency)
 * @method static from(string $currency)
 * @method static to(string $currency)
 *
 *     Exchange::from('USD')->to('EUR')->convert(100)->format();
 *     Exchange::from('USD')->to('NGN')->rate();
 */
class Exchange
{
    protected static ?string $apiKey = null;

    /**
     * @var class-string<ExchangeRateProvider>|ExchangeRateProvider
     */
    protected static string|ExchangeRateProvider $provider = ExchangeRateApi::class;

    protected static ?HttpClient $http = null;

    public function __construct(
        protected ?string $source = null,
        protected ?string $target = null,
    ) {}

    public static function setApiKey(?string $key): void
    {
        static::$apiKey = $key;
    }

    /**
     * Use a provider class (built with the API key and HTTP client) or a ready instance.
     *
     * @param  class-string<ExchangeRateProvider>|ExchangeRateProvider  $provider
     */
    public static function setProvider(string|ExchangeRateProvider $provider): void
    {
        static::$provider = $provider;
    }

    public static function setHttpClient(?HttpClient $http): void
    {
        static::$http = $http;
    }

    /**
     * Back to ExchangeRate-API with no key or HTTP client set.
     */
    public static function reset(): void
    {
        static::$apiKey = null;
        static::$provider = ExchangeRateApi::class;
        static::$http = null;
    }

    public function source(string $currency): static
    {
        $this->source = strtoupper($currency);

        return $this;
    }

    public function target(string $currency): static
    {
        $this->target = strtoupper($currency);

        return $this;
    }

    /**
     * `from()` and `to()` set the currencies, called on the class or an instance.
     */
    public function __call(string $method, array $arguments): static
    {
        return match ($method) {
            'from' => $this->source(...$arguments),
            'to' => $this->target(...$arguments),
            default => throw new \BadMethodCallException("Method {$method} does not exist."),
        };
    }

    public static function __callStatic(string $method, array $arguments): static
    {
        return (new static)->__call($method, $arguments);
    }

    /**
     * `$amount` of the source currency as Money in the target currency.
     */
    public function convert(int|float|string $amount, ?string $source = null, ?string $target = null): Money
    {
        [$source, $target] = $this->pair($source, $target);

        $value = $source === $target ? (float) $amount : static::provider()->convert((float) $amount, $source, $target);

        return Money::of($value, $target);
    }

    /**
     * How much one unit of the source currency is worth in the target currency.
     */
    public function rate(?string $source = null, ?string $target = null): float
    {
        [$source, $target] = $this->pair($source, $target);

        return $source === $target ? 1.0 : static::provider()->rate($source, $target);
    }

    public static function format(int|float|string $amount, string $from, string $to): string
    {
        return (new static($from, $to))
            ->convert($amount)
            ->format();
    }

    public static function provider(): ExchangeRateProvider
    {
        if (static::$provider instanceof ExchangeRateProvider) {
            return static::$provider;
        }

        return new (static::$provider)(static::$apiKey, static::$http);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function pair(?string $source, ?string $target): array
    {
        $source = strtoupper($source ?? $this->source ?? '');
        $target = strtoupper($target ?? $this->target ?? '');

        if ($source === '') {
            throw new ExchangeException('missing-source');
        }

        if ($target === '') {
            throw new ExchangeException('missing-target');
        }

        return [$source, $target];
    }
}
