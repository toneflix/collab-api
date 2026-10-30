<?php

namespace App\Services\Payment;

use App\Helpers\Providers;
use App\Models\PaymentRegion;
use App\Models\User;
use App\Services\Payment\Rates\CurrencyConverter;

/**
 * Decides how each payer pays. A country listed in an active region pays
 * through that region's providers in its currency; everyone else pays in the
 * app currency through every provider that is on.
 */
class PaymentRegions
{
    /**
     * @var array<string, string>|null
     */
    protected static ?array $countryNames = null;

    public function __construct(
        protected PaymentGateways $gateways,
        protected CurrencyConverter $converter,
    ) {}

    public function forUser(User $user, ?string $countryHint = null): PaymentOptions
    {
        return $this->forCountry($this->countryOf($user, $countryHint));
    }

    public function forCountry(?string $country): PaymentOptions
    {
        $appCurrency = $this->appCurrency();
        $enabled = array_map(fn(PaymentGateway $g) => $g->key(), $this->gateways->enabled());
        $region = $country ? $this->regionFor($country) : null;
        $providers = $region ? array_values(array_intersect($region->providers ?? [], $enabled)) : [];

        // A region whose providers are all off pays like everyone else.
        if (! $region || $providers === []) {
            return new PaymentOptions($country, $appCurrency, $enabled);
        }

        return new PaymentOptions(
            $country,
            $region->currency,
            $providers,
            $this->rateFor($region, $appCurrency),
            $region->id,
            $region->name,
        );
    }

    public function regionFor(string $country): ?PaymentRegion
    {
        return PaymentRegion::query()
            ->active()
            ->covering([$country])
            ->oldest('id')
            ->first();
    }

    /**
     * How much one unit of the app currency is worth in the region's currency.
     */
    public function rateFor(PaymentRegion $region, ?string $appCurrency = null): float
    {
        $appCurrency ??= $this->appCurrency();

        if (strtoupper($region->currency) === $appCurrency) {
            return 1.0;
        }

        if ($region->exchange_rate) {
            return (float) $region->exchange_rate;
        }

        return $this->converter->rate($appCurrency, $region->currency) * (1 + $region->rate_markup / 100);
    }

    /**
     * The payer's two-letter country: their payout account's, then their profile's,
     * then the one the request came from.
     */
    public function countryOf(User $user, ?string $hint = null): ?string
    {
        $country = $user->payoutAccount?->country
            ?? static::countryCode($user->country)
            ?? static::countryCode($hint);

        return $country ? strtoupper($country) : null;
    }

    /**
     * A two-letter code from a code or an English country name.
     */
    public static function countryCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || in_array(strtoupper($value), ['XX', 'T1'], true)) {
            return null;
        }

        if (strlen($value) === 2 && ctype_alpha($value)) {
            return strtoupper($value);
        }

        return static::countryNames()[strtolower($value)] ?? null;
    }

    /**
     * @return array<string, string> lower-case English name => code
     */
    protected static function countryNames(): array
    {
        if (static::$countryNames !== null) {
            return static::$countryNames;
        }

        $names = [];

        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $code = $first . $second;
                $name = \Locale::getDisplayRegion("-{$code}", 'en');

                if ($name && $name !== $code && ! str_starts_with($name, 'Unknown')) {
                    $names[strtolower($name)] ??= $code;
                }
            }
        }

        return static::$countryNames = $names;
    }

    protected function appCurrency(): string
    {
        return strtoupper((string) Providers::config('app_currency', 'NGN'));
    }
}
