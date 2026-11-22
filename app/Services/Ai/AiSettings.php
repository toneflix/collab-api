<?php

namespace App\Services\Ai;

use App\Enums\AiEngine;
use App\Enums\VideoCandidate;
use App\Helpers\Providers;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\Track;
use App\Services\Ai\Music\ElevenLabsMusic;
use App\Services\Ai\Music\MiniMaxMusic25;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\SunoMusic;
use App\Services\Ai\Tasks\EditSection;
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
        'ai_music_cf_elevenlabs_credits' => ['ElevenLabs Music v2 Credits per 10 Seconds', '5', 'Credits an ElevenLabs v2 track on Workers AI uses for every 10 seconds of audio. About $0.075 for 30 seconds on Cloudflare.'],
        'ai_music_minimax_26_credits' => ['MiniMax Music 2.6 Credits per Song', '30', 'Credits one MiniMax Music 2.6 song uses, whatever its length. About $0.15 a song on Cloudflare.'],
        'ai_suno_model' => ['Suno Model Version', 'V5_5', 'The Suno version songs are made with. v5.5 and v6 follow the length people ask for.'],
        'ai_suno_keep_both' => ['Keep Both Suno Versions', '0', 'Suno makes two versions of every song. Keep both and let people pick the one that goes on the track, or keep only the first.'],
        'ai_stems_engine' => ['Stem Split Engine', 'replicate', 'Where songs are split into stems: Demucs on Replicate, or Suno.'],
        'ai_stems_credits' => ['Demucs Stem Split Credits', '5', 'Credits one split into stems uses on Replicate.'],
        'ai_stems_suno_type' => ['Suno Stems', 'split_stem', 'Vocals and instrumental only (about $0.05 a split on sunoapi.org), or up to 12 stems (about $0.25).'],
        'ai_stems_suno_credits' => ['Suno Stem Split Credits', '50', 'Credits one split into stems uses on Suno.'],
        'ai_edit_credits' => ['Section Edit Credits', '5', 'Credits one AI edit of a part of a stem uses. It runs on Suno\'s replace section.'],
        'ai_vocals_credits' => ['Sung Vocals Credits', '22', 'Credits singing vocals over a beat written as notes uses. It runs on Suno: a song with vocals added (about $0.06 on sunoapi.org) and a vocal split (about $0.05).'],
        'ai_artwork_engine' => ['Artwork Engine', 'replicate', 'Where artwork is drawn: FLUX.1 [schnell] on Replicate, or on Cloudflare Workers AI.'],
        'ai_artwork_credits' => ['Artwork Credits', '3', 'Credits one artwork request uses. FLUX.1 [schnell] costs about $0.003 an image on Replicate.'],
        'ai_artwork_images' => ['Artwork Images per Request', '4', 'How many images (1 to 4) people pick from for each request.'],
        'ai_video_storyboard_credits' => ['Music Video Storyboard Credits per Minute', '20', 'Credits the scene plan and stills of a music video use for every started minute of the song. Each still costs about $0.03 on Replicate, about 12 a minute.'],
        'ai_video_location_storyboard_credits' => ['Music Video Location Storyboard Credits per Minute', '6', 'Credits the plan and stills of a music video shot in 2 or 3 locations use for every started minute of the song. It draws about 9 stills and 2 character sheets in all, about $0.03 each on Replicate.'],
        'ai_video_credits' => ['Music Video Credits per Minute', '190', 'Credits rendering a music video uses for every started minute of the song. About $2.50 a minute on Replicate with lip-synced singing shots.'],
        'ai_video_broll_model' => ['Music Video B-roll Model', 'p-video', 'The model that animates the shots between the singing: p-video ($0.02/s at 720p), Seedance 1 Pro Fast ($0.025/s) or Wan 2.2 i2v fast (about $0.022/s, 5 s clips).'],
        'ai_video_advanced_credits' => ['Advanced Music Video Credits per Minute', '800', 'Credits rendering an advanced music video uses for every started minute of the song. About $10 a minute on Replicate with OmniHuman 1.5 singing ($0.16/s) and Kling 3.0 b-roll ($0.168/s).'],
        'ai_video_advanced_minutes' => ['Advanced Music Video Minute Rate', '4', 'Plan video minutes one minute of an advanced music video uses.'],
        'ai_video_advanced_singer_model' => ['Advanced Music Video Lip Sync Model', 'omni-human-1.5', 'The model that makes the singing shots of advanced videos: OmniHuman 1.5 ($0.16/s), Kling Avatar 2 ($0.056/s) or VEED Fabric 1.0 ($0.15/s at 720p).'],
        'ai_video_advanced_broll_model' => ['Advanced Music Video B-roll Model', 'kling-v3', 'The model that animates the shots between the singing in advanced videos: Kling 3.0 ($0.168/s), Veo 3.1 Fast ($0.10/s) or Seedance 2.0 ($0.18/s, refuses stills that may show a real person).'],
        'ai_canvas_credits' => ['Canvas Loop Credits', '30', 'Credits one AI canvas loop for a track uses: a tall still drawn from the artwork (about $0.03 on Replicate) animated for 6 seconds with the music video b-roll model (about $0.12 with p-video).'],
        'ai_video_max_seconds' => ['Longest Music Video (seconds)', '360', 'Songs longer than this can\'t get a music video.'],
        'ai_music_max_seconds' => ['Longest Generated Track (seconds)', '300', 'The longest track people can generate at once.'],
        'ai_lyrics_engine' => ['Lyrics Engine', 'replicate', 'Where Whisper writes out lyrics: Replicate, or Cloudflare Workers AI.'],
        'ai_lyrics_credits' => ['Lyrics Credits', '2', 'Credits one lyrics transcription in the studio uses.'],
        'ai_lyrics_auto' => ['Transcribe Released Tracks', '1', 'Write out the lyrics of newly released tracks automatically. The app pays for these, not the creator.'],
        'ai_lyrics_explicit_words' => ['More Explicit Words', null, 'Comma separated words that mark a track explicit, on top of the built-in list.'],
        'ai_lyrics_review_words' => ['More Words to Review', null, 'Comma separated words or phrases that send a track to the moderation queue, on top of the built-in list.'],
        'ai_tags_auto' => ['Detect Tempo and Key', '1', 'Tag new tracks and studio mixes with their BPM and key. This runs on your own server and uses no credits.'],
        'ai_styles_auto' => ['Detect Genre and Mood', '1', 'Tag new tracks and studio mixes with the moods their audio carries and suggest the genre it sounds like. Runs our own model on Replicate, paid by the app; it stays off until the model below is set.'],
        'ai_styles_model' => ['Genre and Mood Model', null, 'The Replicate model pushed from models/style-tagger by the "Push style tagger" workflow, like toneflix/style-tagger.'],
        'ai_styles_lyrics' => ['Read Lyrics for Mood', '1', 'Let the Composer writing model read a track\'s lyrics and count the moods they carry alongside the audio.'],
        'ai_moderator_claims_auto' => ['Review Artist Claims Automatically', '0', 'Send every new artist claim to the AI moderator. Claims it isn\'t confident about are flagged for a person.'],
        'ai_moderator_releases_auto' => ['Review Releases Automatically', '0', 'Send every release waiting for approval to the AI moderator. Releases it isn\'t confident about are flagged for a person.'],
        'ai_moderator_origins_auto' => ['Rank Direct Uploads Automatically', '0', 'Send every track uploaded straight to a release to the AI moderator, to judge whether it is recorded music or agentic. Tracks it isn\'t confident about are flagged for a person.'],
        'ai_moderator_engine' => ['AI Moderator Engine', 'replicate', 'Where the AI moderator\'s language model runs: Replicate, or Cloudflare Workers AI.'],
        'ai_moderator_confidence' => ['Confidence Needed', '80', 'How sure (0 to 100) the AI moderator must be to decide an automatic review on its own.'],
        'ai_assistant_engine' => ['Admin Assistant Engine', 'replicate', 'Where the admin assistant\'s language model runs: Replicate, or Cloudflare Workers AI.'],
        'ai_composer_engine' => ['Composer Engine', 'replicate', 'Where Composer\'s writing model runs: Replicate, or Cloudflare Workers AI.'],
        'ai_composer_model' => ['Composer Writing Model', null, 'The Replicate language model that suggests lyrics in Composer, like anthropic/claude-4.5-haiku.'],
        'ai_composer_cloudflare_model' => ['Composer Writing Model on Cloudflare', null, 'The Workers AI language model that suggests lyrics in Composer, like anthropic/claude-haiku-4.5 or @cf/openai/gpt-oss-120b.'],
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
        'cloudflare' => [
            'CLOUDFLARE_ACCOUNT_ID' => ['Cloudflare Account ID', 'ai.providers.cloudflare.account'],
            'CLOUDFLARE_API_TOKEN' => ['Cloudflare API Token', 'ai.providers.cloudflare.key'],
        ],
    ];

    /**
     * The engines each switchable tool can run on, by its setting.
     *
     * @var array<string, list<AiEngine>>
     */
    public const ENGINES = [
        'ai_stems_engine' => [AiEngine::REPLICATE, AiEngine::SUNO],
        'ai_lyrics_engine' => [AiEngine::REPLICATE, AiEngine::CLOUDFLARE],
        'ai_composer_engine' => [AiEngine::REPLICATE, AiEngine::CLOUDFLARE],
        'ai_moderator_engine' => [AiEngine::REPLICATE, AiEngine::CLOUDFLARE],
        'ai_assistant_engine' => [AiEngine::REPLICATE, AiEngine::CLOUDFLARE],
        'ai_artwork_engine' => [AiEngine::REPLICATE, AiEngine::CLOUDFLARE],
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
     * The engine a switchable tool runs on, by its setting in ENGINES.
     */
    public static function engine(string $setting): AiEngine
    {
        $engine = AiEngine::tryFrom((string) self::get($setting));

        return in_array($engine, self::ENGINES[$setting] ?? [], true) ? $engine : AiEngine::REPLICATE;
    }

    /**
     * The engine new stem splits run on.
     */
    public static function stemsEngine(): AiEngine
    {
        return self::engine('ai_stems_engine');
    }

    /**
     * The engine Whisper writes out lyrics on.
     */
    public static function lyricsEngine(): AiEngine
    {
        return self::engine('ai_lyrics_engine');
    }

    /**
     * Every engine the admin's choices use, the music model's included.
     *
     * @return list<AiEngine>
     */
    public static function chosenEngines(): array
    {
        return collect(array_keys(self::ENGINES))
            ->map(fn(string $setting) => self::engine($setting))
            ->push(self::musicModel()->engine())
            ->unique(fn(AiEngine $engine) => $engine->value)
            ->values()
            ->all();
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
        return self::enabled() && self::lyricsEngine()->available() && filter_var(self::get('ai_lyrics_auto'), FILTER_VALIDATE_BOOL);
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
     * Whether new audio gets its moods and genre detected: the setting is on,
     * the model is set and Replicate has its key.
     */
    public static function autoStyles(): bool
    {
        return filter_var(self::get('ai_styles_auto'), FILTER_VALIDATE_BOOL)
            && self::styleModel() !== null
            && AiEngine::REPLICATE->available();
    }

    /**
     * The Replicate model that hears genre and mood, or null until set.
     */
    public static function styleModel(): ?string
    {
        return self::get('ai_styles_model') ?: null;
    }

    /**
     * Whether the lyrics count towards a track's moods.
     */
    public static function stylesFromLyrics(): bool
    {
        return filter_var(self::get('ai_styles_lyrics'), FILTER_VALIDATE_BOOL);
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
    public static function autoModerates(ArtistClaim|Release|Track $subject): bool
    {
        $key = match (true) {
            $subject instanceof ArtistClaim => 'ai_moderator_claims_auto',
            $subject instanceof Track => 'ai_moderator_origins_auto',
            default => 'ai_moderator_releases_auto',
        };

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
     * The Workers AI language model that suggests lyrics in Composer.
     */
    public static function composerCloudflareModel(): string
    {
        return trim((string) self::get('ai_composer_cloudflare_model'));
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

    /**
     * Credits one AI edit of a part of a stem uses.
     */
    public static function editCredits(): int
    {
        return max(1, (int) self::get('ai_edit_credits'));
    }

    /**
     * Credits singing vocals over a beat uses.
     */
    public static function vocalsCredits(): int
    {
        return max(1, (int) self::get('ai_vocals_credits'));
    }

    /**
     * The engine artwork is drawn on.
     */
    public static function artworkEngine(): AiEngine
    {
        return self::engine('ai_artwork_engine');
    }

    /**
     * Credits one artwork request uses.
     */
    public static function artworkCredits(): int
    {
        return max(1, (int) self::get('ai_artwork_credits'));
    }

    /**
     * How many images one artwork request draws.
     */
    public static function artworkImages(): int
    {
        return min(4, max(1, (int) self::get('ai_artwork_images')));
    }

    /**
     * Credits the storyboard of a music video uses per started minute.
     */
    public static function videoStoryboardCredits(): int
    {
        return max(1, (int) self::get('ai_video_storyboard_credits'));
    }

    /**
     * Credits the storyboard of a music video shot in a few locations uses
     * per started minute.
     */
    public static function videoLocationStoryboardCredits(): int
    {
        return max(1, (int) self::get('ai_video_location_storyboard_credits'));
    }

    /**
     * Credits rendering a music video uses per started minute.
     */
    public static function videoCredits(): int
    {
        return max(1, (int) self::get('ai_video_credits'));
    }

    /**
     * Credits one AI canvas loop uses.
     */
    public static function canvasCredits(): int
    {
        return max(1, (int) self::get('ai_canvas_credits'));
    }

    /**
     * The model that animates b-roll shots.
     */
    public static function videoBrollModel(): VideoCandidate
    {
        $candidate = VideoCandidate::tryFrom((string) self::get('ai_video_broll_model'));

        return $candidate && ! $candidate->sings() ? $candidate : VideoCandidate::P_VIDEO;
    }

    /**
     * Credits rendering an advanced music video uses per started minute.
     */
    public static function videoAdvancedCredits(): int
    {
        return max(1, (int) self::get('ai_video_advanced_credits'));
    }

    /**
     * Plan video minutes one minute of an advanced music video uses.
     */
    public static function videoAdvancedMinuteRate(): int
    {
        return min(20, max(1, (int) self::get('ai_video_advanced_minutes')));
    }

    /**
     * The model that makes the singing shots of advanced videos.
     */
    public static function videoAdvancedSingerModel(): VideoCandidate
    {
        $candidate = VideoCandidate::tryFrom((string) self::get('ai_video_advanced_singer_model'));

        return $candidate && array_key_exists($candidate->value, VideoCandidate::advancedOptions(true))
            ? $candidate
            : VideoCandidate::OMNI_HUMAN_15;
    }

    /**
     * The model that animates b-roll shots of advanced videos.
     */
    public static function videoAdvancedBrollModel(): VideoCandidate
    {
        $candidate = VideoCandidate::tryFrom((string) self::get('ai_video_advanced_broll_model'));

        return $candidate && array_key_exists($candidate->value, VideoCandidate::advancedOptions(false))
            ? $candidate
            : VideoCandidate::KLING_V3;
    }

    public static function videoMaxSeconds(): int
    {
        return min(600, max(30, (int) self::get('ai_video_max_seconds')));
    }

    public static function musicMaxSeconds(): int
    {
        return min(300, max(10, (int) self::get('ai_music_max_seconds')));
    }

    /**
     * What the app needs to show the AI tools and their costs.
     *
     * @return array{enabled: bool, creditPrice: float, packs: list<array{credits: int, discount: float, price: float}>, costs: array<string, int>, music: array<string, mixed>, stemsEngine: string, edit: array{engine: string, minSeconds: float, maxShare: float}, engines: array<string, bool>}
     */
    public static function clientConfig(): array
    {
        $editor = EditSection::editor();

        return [
            'enabled' => self::enabled(),
            'creditPrice' => self::creditPrice(),
            'packs' => self::packs(),
            'costs' => [
                'stems' => self::stemsCredits(),
                'lyrics' => self::lyricsCredits(),
                'edit' => self::editCredits(),
                'vocals' => self::vocalsCredits(),
                'artwork' => self::artworkCredits(),
                'videoStoryboard' => self::videoStoryboardCredits(),
                'videoLocationStoryboard' => self::videoLocationStoryboardCredits(),
                'video' => self::videoCredits(),
                'videoAdvanced' => self::videoAdvancedCredits(),
                'videoAdvancedMinutes' => self::videoAdvancedMinuteRate(),
                'canvas' => self::canvasCredits(),
                'composer' => collect(['line', 'block', 'verse', 'rewrite'])
                    ->mapWithKeys(fn(string $kind) => [$kind => self::composerCredits($kind)])
                    ->all(),
            ],
            'music' => self::musicModel()->toClient(),
            'stemsEngine' => self::stemsEngine()->value,
            'edit' => [
                'engine' => $editor->engine()->value,
                'minSeconds' => $editor->minSeconds(),
                'maxShare' => $editor->maxShare(),
            ],
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

        if ($key === 'ai_composer_cloudflare_model') {
            return (string) config('ai.providers.cloudflare.models.composer.default');
        }

        if ($key === 'ai_credit_price') {
            $currency = strtoupper((string) Providers::config('app_currency', 'USD'));

            return (string) (self::CREDIT_PRICES[$currency] ?? self::CREDIT_PRICES['USD']);
        }

        return $field[1];
    }
}
