<?php

namespace App\Services\Ai;

use App\Helpers\Providers;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Services\Ai\Music\ElevenLabsMusic;
use App\Services\Ai\Music\MusicModel;
use App\Services\Settings\SettingsStore;

/**
 * Admin settings for the AI tools: whether they're on, what credits cost and
 * how many credits each tool uses. The Replicate token comes from `.env`.
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
        'ai_music_model' => ['Music Model', ElevenLabsMusic::ID, 'The model the studio generates music with.'],
        'ai_music_credits' => ['ElevenLabs Music Credits per 10 Seconds', '5', 'Credits an ElevenLabs track uses for every 10 seconds of audio. About $0.08 for 30 seconds on Replicate.'],
        'ai_music_minimax_25_credits' => ['MiniMax Music 2.5 Credits per Song', '30', 'Credits one MiniMax Music 2.5 song uses, whatever its length.'],
        'ai_music_minimax_15_credits' => ['MiniMax Music 1.5 Credits per Song', '10', 'Credits one MiniMax Music 1.5 song uses, whatever its length.'],
        'ai_music_stable_audio_credits' => ['Stable Audio 2.5 Credits per Track', '40', 'Credits one Stable Audio 2.5 track uses, whatever its length.'],
        'ai_stems_credits' => ['Stem Split Credits', '5', 'Credits one split into stems uses.'],
        'ai_music_max_seconds' => ['Longest Generated Track (seconds)', '300', 'The longest track people can generate at once.'],
        'ai_lyrics_credits' => ['Lyrics Credits', '2', 'Credits one lyrics transcription in the studio uses.'],
        'ai_lyrics_auto' => ['Transcribe Released Tracks', '1', 'Write out the lyrics of newly released tracks automatically. The app pays for these, not the creator.'],
        'ai_lyrics_explicit_words' => ['More Explicit Words', null, 'Comma separated words that mark a track explicit, on top of the built-in list.'],
        'ai_lyrics_review_words' => ['More Words to Review', null, 'Comma separated words or phrases that send a track to the moderation queue, on top of the built-in list.'],
        'ai_tags_auto' => ['Detect Tempo and Key', '1', 'Tag new tracks and studio mixes with their BPM and key. This runs on your own server and uses no credits.'],
        'ai_moderator_claims_auto' => ['Review Artist Claims Automatically', '0', 'Send every new artist claim to the AI moderator. Claims it isn\'t confident about are flagged for a person.'],
        'ai_moderator_releases_auto' => ['Review Releases Automatically', '0', 'Send every release waiting for approval to the AI moderator. Releases it isn\'t confident about are flagged for a person.'],
        'ai_moderator_confidence' => ['Confidence Needed', '80', 'How sure (0 to 100) the AI moderator must be to decide an automatic review on its own.'],
    ];

    public const KEYS = [
        'replicate' => [
            'REPLICATE_API_TOKEN' => ['Replicate API Token', 'ai.providers.replicate.key'],
        ],
    ];

    /**
     * Whether admins turned the AI tools on and the Replicate token is set.
     */
    public static function enabled(): bool
    {
        return filter_var(self::get('ai_enabled'), FILTER_VALIDATE_BOOL) && self::configured('replicate');
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
            ->map(fn($pack) => array_map('trim', explode(':', $pack, 2)))
            ->filter(fn($pack) => (int) $pack[0] > 0)
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
        return collect(self::packs())
            ->firstWhere('credits', $credits);
    }

    /**
     * The model the studio generates music with.
     */
    public static function musicModel(): MusicModel
    {
        return MusicModel::find(self::get('ai_music_model')) ?? new ElevenLabsMusic;
    }

    public static function stemsCredits(): int
    {
        return max(1, (int) self::get('ai_stems_credits'));
    }

    public static function lyricsCredits(): int
    {
        return max(1, (int) self::get('ai_lyrics_credits'));
    }

    /**
     * Whether released tracks get their lyrics transcribed when uploaded.
     */
    public static function autoLyrics(): bool
    {
        return self::enabled() && filter_var(self::get('ai_lyrics_auto'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Whether new audio gets its tempo and key detected. This works without
     * Replicate, since the analysis runs on the server.
     */
    public static function autoTags(): bool
    {
        return filter_var(self::get('ai_tags_auto'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Words admins added to a lyrics check list, "explicit" or "review".
     *
     * @return list<string>
     */
    public static function lyricsWords(string $list): array
    {
        return collect(explode(',', (string) self::get("ai_lyrics_{$list}_words")))
            ->map(fn(string $word) => mb_strtolower(trim($word)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether new items of this kind go to the AI moderator on their own.
     */
    public static function autoModerates(ArtistClaim|Release $subject): bool
    {
        $key = $subject instanceof ArtistClaim ? 'ai_moderator_claims_auto' : 'ai_moderator_releases_auto';

        return filter_var(self::get($key), FILTER_VALIDATE_BOOL);
    }

    public static function moderatorConfidence(): int
    {
        return min(100, max(0, (int) self::get('ai_moderator_confidence')));
    }

    public static function musicMaxSeconds(): int
    {
        return min(300, max(10, (int) self::get('ai_music_max_seconds')));
    }

    /**
     * What the app needs to show the AI tools and their costs.
     *
     * @return array{enabled: bool, creditPrice: float, packs: list<array{credits: int, discount: float, price: float}>, costs: array<string, int>, music: array<string, mixed>}
     */
    public static function clientConfig(): array
    {
        return [
            'enabled' => self::enabled(),
            'creditPrice' => self::creditPrice(),
            'packs' => self::packs(),
            'costs' => [
                'stems' => self::stemsCredits(),
                'lyrics' => self::lyricsCredits(),
            ],
            'music' => self::musicModel()->toClient(),
        ];
    }

    /**
     * Every music model admins can switch to.
     *
     * @return list<array<string, mixed>>
     */
    public static function musicModels(): array
    {
        return array_values(array_map(fn(MusicModel $model) => $model->toClient(), MusicModel::all()));
    }

    /**
     * The credit price follows the app currency until an admin sets one.
     *
     * @param  array{0: string, 1: string|null, 2?: string}  $field
     */
    protected static function fallback(string $key, array $field): ?string
    {
        if ($key === 'ai_music_model') {
            $configured = (string) config('ai.providers.replicate.models.audio.default');

            return MusicModel::find($configured) ? $configured : $field[1];
        }

        if ($key === 'ai_credit_price') {
            $currency = strtoupper((string) Providers::config('app_currency', 'USD'));

            return (string) (self::CREDIT_PRICES[$currency] ?? self::CREDIT_PRICES['USD']);
        }

        return $field[1];
    }
}
