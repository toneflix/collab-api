<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiEngine;
use App\Enums\HttpStatus;
use App\Enums\VideoCandidate;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\SunoMusic;
use App\Services\Composer\ComposerSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lets admins switch the AI tools on or off, pick each tool's engine and set
 * what credits cost.
 */
class AiSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change AI settings.'
            );

            return $next($request);
        });
    }

    public function show()
    {
        return $this->settings();
    }

    public function update(Request $request)
    {
        $valid = $request->validate([
            'ai_enabled' => ['nullable', 'boolean'],
            'ai_credit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'ai_credit_packs' => ['nullable', 'string', 'max:200', 'regex:/^\s*\d+(\s*:\s*\d+(\.\d+)?)?(\s*,\s*\d+(\s*:\s*\d+(\.\d+)?)?)*\s*$/'],
            'ai_music_model' => ['nullable', 'string', Rule::in(array_keys(MusicModel::all()))],
            ...collect(MusicModel::all())->mapWithKeys(fn(MusicModel $model) => [
                $model::CREDITS_SETTING => ['nullable', 'integer', 'between:1,1000'],
            ])->all(),
            'ai_suno_model' => ['nullable', 'string', Rule::in(array_keys(SunoMusic::VERSIONS))],
            'ai_suno_keep_both' => ['nullable', 'boolean'],
            ...collect(AiSettings::ENGINES)
                ->map(fn(array $engines) => ['nullable', 'string', Rule::in(array_map(fn(AiEngine $engine) => $engine->value, $engines))])
                ->all(),
            'ai_stems_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_stems_suno_type' => ['nullable', 'string', Rule::in(array_keys(AiSettings::SUNO_STEM_TYPES))],
            'ai_stems_suno_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_edit_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_vocals_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_artwork_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_artwork_images' => ['nullable', 'integer', 'between:1,4'],
            'ai_music_max_seconds' => ['nullable', 'integer', 'between:10,300'],
            'ai_video_storyboard_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_video_location_storyboard_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_video_credits' => ['nullable', 'integer', 'between:1,5000'],
            'ai_video_broll_model' => ['nullable', 'string', Rule::in(array_keys(VideoCandidate::brollOptions()))],
            'ai_video_advanced_credits' => ['nullable', 'integer', 'between:1,20000'],
            'ai_video_advanced_minutes' => ['nullable', 'integer', 'between:1,20'],
            'ai_video_advanced_singer_model' => ['nullable', 'string', Rule::in(array_keys(VideoCandidate::advancedOptions(true)))],
            'ai_video_advanced_broll_model' => ['nullable', 'string', Rule::in(array_keys(VideoCandidate::advancedOptions(false)))],
            'ai_video_max_seconds' => ['nullable', 'integer', 'between:30,600'],
            'ai_lyrics_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_lyrics_auto' => ['nullable', 'boolean'],
            'ai_lyrics_explicit_words' => ['nullable', 'string', 'max:2000'],
            'ai_lyrics_review_words' => ['nullable', 'string', 'max:2000'],
            'ai_tags_auto' => ['nullable', 'boolean'],
            'ai_styles_auto' => ['nullable', 'boolean'],
            'ai_styles_model' => ['nullable', 'string', 'max:150', 'regex:/^[\w.-]+\/[\w.-]+(:[\w]+)?$/'],
            'ai_styles_lyrics' => ['nullable', 'boolean'],
            'ai_moderator_claims_auto' => ['nullable', 'boolean'],
            'ai_moderator_releases_auto' => ['nullable', 'boolean'],
            'ai_moderator_origins_auto' => ['nullable', 'boolean'],
            'ai_moderator_confidence' => ['nullable', 'integer', 'between:0,100'],
            'ai_composer_model' => ['nullable', 'string', 'max:150', 'regex:/^[\w.-]+\/[\w.-]+(:[\w]+)?$/'],
            'ai_composer_cloudflare_model' => ['nullable', 'string', 'max:150', 'regex:/^(@cf\/)?[\w.-]+\/[\w.-]+$/'],
            'ai_composer_line_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_composer_block_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_composer_verse_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_composer_rewrite_credits' => ['nullable', 'integer', 'between:1,1000'],
            'composer_word_source' => ['nullable', 'string', Rule::in(ComposerSettings::WORD_SOURCES)],
            'ai_composer_music_model' => [
                'nullable',
                'string',
                Rule::in(array_map(fn(MusicModel $model) => $model::ID, AiSettings::lyricMusicModels())),
            ],
        ], [
            'ai_composer_model.regex' => 'Use a Replicate model name like anthropic/claude-4.5-haiku.',
            'ai_styles_model.regex' => 'Use a Replicate model name like toneflix/style-tagger.',
            'ai_composer_cloudflare_model.regex' => 'Use a Workers AI model name like anthropic/claude-haiku-4.5 or @cf/openai/gpt-oss-120b.',
            'ai_composer_music_model.in' => 'Pick a music model that sings lyrics.',
            'ai_credit_packs.regex' => 'List pack sizes like "100, 500:10, 2000:20".',
        ]);

        AiSettings::update($valid);
        ComposerSettings::update($valid);

        return $this->settings($this->savedMessage(), HttpStatus::ACCEPTED);
    }

    /**
     * Say which chosen engines still need their key in `.env`.
     */
    protected function savedMessage(): string
    {
        $missing = collect(AiSettings::chosenEngines())
            ->reject(fn(AiEngine $engine) => $engine->available())
            ->map(fn(AiEngine $engine) => $engine->missingKeys());

        return $missing->isEmpty()
            ? 'AI settings saved.'
            : 'Saved. Set ' . $missing->join(' and ') . ' in .env for the tools that use them.';
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        $settings = [
            'fields' => [...AiSettings::forAdmin(), ...ComposerSettings::forAdmin()],
            'keys' => [...AiSettings::keys(), ...ComposerSettings::keys()],
            'client' => AiSettings::clientConfig(),
            'models' => AiSettings::musicModels(),
            'options' => [
                'sunoVersions' => SunoMusic::VERSIONS,
                'sunoStemTypes' => AiSettings::SUNO_STEM_TYPES,
                'videoBrollModels' => VideoCandidate::brollOptions(),
                'videoAdvancedSingerModels' => VideoCandidate::advancedOptions(true),
                'videoAdvancedBrollModels' => VideoCandidate::advancedOptions(false),
                'wordSources' => ['datamuse' => 'Datamuse', 'local' => 'Built-in dictionary'],
                'composerMusicModels' => array_map(fn(MusicModel $model) => $model::ID, AiSettings::lyricMusicModels()),
                'engines' => collect(AiEngine::cases())
                    ->mapWithKeys(fn(AiEngine $engine) => [$engine->value => $engine->label()])
                    ->all(),
                'toolEngines' => collect(AiSettings::ENGINES)
                    ->map(fn(array $engines) => array_map(fn(AiEngine $engine) => $engine->value, $engines))
                    ->all(),
            ],
            'currency' => (string) Providers::config('app_currency', 'USD'),
        ];

        return (new SettingsResource($settings))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
