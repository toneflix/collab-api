<?php

namespace App\Services\Ai;

use App\Enums\AiEngine;
use App\Helpers\Providers;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Services\Ai\Music\ElevenLabsMusic;
use App\Services\Ai\Music\MiniMaxMusic25;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\SunoMusic;
use App\Services\Settings\SettingsStore;

/**
 * Admin settings for the AI tools: whether they're on, which engine each tool
 * runs on, what credits cost and how many credits each tool uses. Engine keys
 * come from `.env`.
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
        'ai_music_suno_credits' => ['Suno Credits per Song', '12', 'Credits one Suno song uses, whatever its length. A request costs about $0.06 on sunoapi.org and makes two versions.'],
        'ai_suno_model' => ['Suno Model Version', 'V5_5', 'The Suno version songs are made with. v5.5 and v6 follow the length people ask for.'],
        'ai_suno_keep_both' => ['Keep Both Suno Versions', '0', 'Suno makes two versions of every song. Keep both and let people pick the one that goes on the track, or keep only the first.'],
        'ai_stems_engine' => ['Stem Split Engine', 'replicate', 'Where songs are split into stems: Demucs on Replicate, or Suno.'],
        'ai_stems_credits' => ['Demucs Stem Split Credits', '5', 'Credits one split into stems uses on Replicate.'],
        'ai_stems_suno_type' => ['Suno Stems', 'split_stem', 'Vocals and instrumental only (about $0.05 a split on sunoapi.org), or up to 12 stems (about $0.25).'],
        'ai_stems_suno_credits' => ['Suno Stem Split Credits', '50', 'Credits one split into stems uses on Suno.'],
        'ai_music_max_seconds' => ['Longest Generated Track (seconds)', '300', 'The longest track people can generate at once.'],
        'ai_lyrics_credits' => ['Lyrics Credits', '2', 'Credits one lyrics transcription in the studio uses.'],
        'ai_lyrics_auto' => ['Transcribe Released Tracks', '1', 'Write out the lyrics of newly released tracks automatically. The app pays for these, not the creator.'],
        'ai_lyrics_explicit_words' => ['More Explicit Words', null, 'Comma separated words that mark a track explicit, on top of the built-in list.'],
        'ai_lyrics_review_words' => ['More Words to Review', null, 'Comma separated words or phrases that send a track to the moderation queue, on top of the built-in list.'],
        'ai_tags_auto' => ['Detect Tempo and Key', '1', 'Tag new tracks and studio mixes with their BPM and key. This runs on your own server and uses no credits.'],
        'ai_moderator_claims_auto' => ['Review Artist Claims Automatically', '0', 'Send every new artist claim to the AI moderator. Claims it isn\'t confident about are flagged for a person.'],
        'ai_moderator_releases_auto' => ['Review Releases Automatically', '0', 'Send every release waiting for approval to the AI moderator. Releases it isn\'t confident about are flagged for a person.'],
        'ai_moderator_confidence' => ['Confidence Needed', '80', 'How sure (0 to 100) the AI moderator must be to decide an automatic review on its own.'],
        'ai_composer_model' => ['Composer Writing Model', null, 'The Replicate language model that suggests lyrics in Composer, like anthropic/claude-4.5-haiku.'],
        'ai_composer_line_credits' => ['Composer Line Credits', '1', 'Credits one ask for the next line uses. It returns three options.'],
        'ai_composer_block_credits' => ['Composer Block Credits', '1', 'Credits one ask for the next four lines uses.'],
        'ai_composer_verse_credits' => ['Composer Verse Credits', '2', 'Credits one ask for a full verse uses.'],
        'ai_composer_rewrite_credits' => ['Composer Rewrite Credits', '1', 'Credits one ask for other takes on a line uses.'],
        'ai_composer_music_model' => ['Composer Song Model', MiniMaxMusic25::ID, 'The music model that sings songs made from Composer lyrics. Only models that take lyrics can be picked.'],
    ];

    public const KEYS = [
        'replicate' => [
            'REPLICATE_API_TOKEN' => ['Replicate API Token', 'ai.providers.replicate.key'],
        ],
        'suno' => [
            'SUNO_API_KEY' => ['Suno API Key', 'ai.providers.suno.key'],
        ],
    ];

    /** Kinds of Suno stem splits, with the label admins see */
    public const SUNO_STEM_TYPES = [
        'separate_vocal' => 'Vocals and instrumental',
        'split_stem' => 'Up to 12 stems',
    ];

    /**
     * Whether admins turned the AI tools on and an engine's key is set.
     */
    public static function enabled(): bool
    {
        return filter_var(self::get('ai_enabled'), FILTER_VALIDATE_BOOL)
            && collect(AiEngine::cases())->contains(fn(AiEngine $engine) => $engine->available());
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

    /**
     * The engine new stem splits run on.
     */
    public static function stemsEngine(): AiEngine
    {
        return AiEngine::tryFrom((string) self::get('ai_stems_engine')) ?? AiEngine::REPLICATE;
    }

    /**
     * Credits one split uses on an engine, the current one by default.
     */
    public static function stemsCredits(?AiEngine $engine = null): int
    {
        $key = ($engine ?? self::stemsEngine()) === AiEngine::SUNO ? 'ai_stems_suno_credits' : 'ai_stems_credits';

        return max(1, (int) self::get($key));
    }

    /**
     * The kind of split Suno makes, separate_vocal or split_stem.
     */
    public static function sunoStemsType(): string
    {
        $type = (string) self::get('ai_stems_suno_type');

        return isset(self::SUNO_STEM_TYPES[$type]) ? $type : 'split_stem';
    }

    /**
     * The Suno model version, like V5_5.
     */
    public static function sunoVersion(): string
    {
        $version = (string) self::get('ai_suno_model');

        return isset(SunoMusic::VERSIONS[$version]) ? $version : 'V5_5';
    }

    /**
     * Whether people keep both versions of a Suno song and pick one.
     */
    public static function sunoKeepsBoth(): bool
    {
        return filter_var(self::get('ai_suno_keep_both'), FILTER_VALIDATE_BOOL);
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
        return self::enabled() && AiEngine::REPLICATE->available() && filter_var(self::get('ai_lyrics_auto'), FILTER_VALIDATE_BOOL);
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

    /**
     * The Replicate model that suggests lyrics in Composer.
     */
    public static function composerModel(): string
    {
        return trim((string) self::get('ai_composer_model'));
    }

    /**
     * Credits one Composer suggestion of a kind (line, block, verse, rewrite) uses.
     */
    public static function composerCredits(string $kind): int
    {
        return max(1, (int) self::get("ai_composer_{$kind}_credits"));
    }

    /**
     * The music model that sings songs made from Composer lyrics.
     */
    public static function composerMusicModel(): MusicModel
    {
        $model = MusicModel::find(self::get('ai_composer_music_model'));

        return $model && $model->takesLyrics() ? $model : new MiniMaxMusic25;
    }

    /**
     * Music models that sing lyrics, which Composer songs can be made with.
     *
     * @return list<MusicModel>
     */
    public static function lyricMusicModels(): array
    {
        return array_values(array_filter(MusicModel::all(), fn(MusicModel $model) => $model->takesLyrics()));
    }

    public static function musicMaxSeconds(): int
    {
        return min(300, max(10, (int) self::get('ai_music_max_seconds')));
    }

    /**
     * What the app needs to show the AI tools and their costs.
     *
     * @return array{enabled: bool, creditPrice: float, packs: list<array{credits: int, discount: float, price: float}>, costs: array<string, int>, music: array<string, mixed>, stemsEngine: string, engines: array<string, bool>}
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
                'composer' => collect(['line', 'block', 'verse', 'rewrite'])
                    ->mapWithKeys(fn(string $kind) => [$kind => self::composerCredits($kind)])
                    ->all(),
            ],
            'music' => self::musicModel()->toClient(),
            'stemsEngine' => self::stemsEngine()->value,
            'engines' => collect(AiEngine::cases())
                ->mapWithKeys(fn(AiEngine $engine) => [$engine->value => $engine->available()])
                ->all(),
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

        if ($key === 'ai_composer_model') {
            return (string) config('ai.providers.replicate.models.composer.default');
        }

        if ($key === 'ai_credit_price') {
            $currency = strtoupper((string) Providers::config('app_currency', 'USD'));

            return (string) (self::CREDIT_PRICES[$currency] ?? self::CREDIT_PRICES['USD']);
        }

        return $field[1];
    }
}
