<?php

namespace App\Services\Ai;

use App\Helpers\Providers;
use App\Services\Settings\SettingsStore;

/**
 * Admin settings for the AI tools: whether they're on, what credits cost and
 * how many credits each tool uses.
 */
class AiSettings extends SettingsStore
{
    protected const CACHE_KEY = 'ai::settings';

    /**
     * A credit's default price in common currencies, about $0.02, so a credit
     * sells for at least four times what the work behind it costs us.
     */
    public const CREDIT_PRICES = [
        'USD' => 0.02, 'EUR' => 0.02, 'GBP' => 0.016, 'CAD' => 0.028, 'AUD' => 0.03,
        'NGN' => 30, 'GHS' => 0.3, 'KES' => 2.6, 'ZAR' => 0.37, 'UGX' => 75, 'TZS' => 55, 'XOF' => 12, 'XAF' => 12,
    ];

    /**
     * Every setting: key => [title, default, hint].
     *
     * @var array<string, array{0: string, 1: string|null, 2?: string}>
     */
    public const FIELDS = [
        'ai_enabled' => ['AI Tools', '1', 'Let people on plans that include AI tools use them.'],
        'ai_credit_price' => ['Price per Credit', null, 'What one bought credit costs, in the app currency.'],
        'ai_credit_packs' => ['Credit Packs', '100, 500:10, 2000:20', 'Comma separated pack sizes. Add a colon and a percentage for a discount, like 500:10.'],
        'ai_music_credits' => ['Music Credits per 10 Seconds', '5', 'Credits a generated track uses for every 10 seconds of audio.'],
        'ai_stems_credits' => ['Stem Split Credits', '5', 'Credits one split into stems uses.'],
        'ai_music_max_seconds' => ['Longest Generated Track (seconds)', '300', 'The longest track people can generate at once.'],
    ];

    /**
     * Whether admins turned the AI tools on.
     */
    public static function enabled(): bool
    {
        return filter_var(self::get('ai_enabled'), FILTER_VALIDATE_BOOL);
    }

    public static function creditPrice(): float
    {
        return max(0.0, (float) self::get('ai_credit_price'));
    }

    /**
     * The credit packs on sale, smallest first.
     *
     * @return list<array{credits: int, discount: float, price: float}>
     */
    public static function packs(): array
    {
        $price = self::creditPrice();

        return collect(explode(',', (string) self::get('ai_credit_packs')))
            ->map(fn ($pack) => array_map('trim', explode(':', $pack, 2)))
            ->filter(fn ($pack) => (int) $pack[0] > 0)
            ->map(function ($pack) use ($price) {
                $credits = (int) $pack[0];
                $discount = min(90, max(0, round((float) ($pack[1] ?? 0), 2)));

                return [
                    'credits' => $credits,
                    'discount' => $discount,
                    'price' => round($credits * $price * (1 - $discount / 100), 2),
                ];
            })
            ->unique('credits')
            ->sortBy('credits')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array{credits: int, discount: float, price: float}|null
     */
    public static function pack(int $credits): ?array
    {
        return collect(self::packs())->firstWhere('credits', $credits);
    }

    /**
     * Credits a generated track of this many seconds uses.
     */
    public static function musicCredits(int $seconds): int
    {
        return max(1, (int) ceil($seconds / 10 * max(1, (int) self::get('ai_music_credits'))));
    }

    public static function stemsCredits(): int
    {
        return max(1, (int) self::get('ai_stems_credits'));
    }

    public static function musicMaxSeconds(): int
    {
        return min(300, max(10, (int) self::get('ai_music_max_seconds')));
    }

    /**
     * What the app needs to show the AI tools and their costs.
     *
     * @return array{enabled: bool, creditPrice: float, packs: list<array{credits: int, discount: float, price: float}>, costs: array<string, int>, musicMaxSeconds: int}
     */
    public static function clientConfig(): array
    {
        return [
            'enabled' => self::enabled(),
            'creditPrice' => self::creditPrice(),
            'packs' => self::packs(),
            'costs' => [
                'music10s' => max(1, (int) self::get('ai_music_credits')),
                'stems' => self::stemsCredits(),
            ],
            'musicMaxSeconds' => self::musicMaxSeconds(),
        ];
    }

    /**
     * The credit price follows the app currency until an admin sets one.
     *
     * @param  array{0: string, 1: string|null, 2?: string}  $field
     */
    protected static function fallback(string $key, array $field): ?string
    {
        if ($key === 'ai_credit_price') {
            $currency = strtoupper((string) Providers::config('app_currency', 'USD'));

            return (string) (self::CREDIT_PRICES[$currency] ?? self::CREDIT_PRICES['USD']);
        }

        return $field[1];
    }
}
