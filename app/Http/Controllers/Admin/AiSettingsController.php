<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiEngine;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Music\MusicModel;
use App\Services\Ai\Music\SunoMusic;
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
            'ai_stems_engine' => ['nullable', 'string', Rule::enum(AiEngine::class)],
            'ai_stems_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_stems_suno_type' => ['nullable', 'string', Rule::in(array_keys(AiSettings::SUNO_STEM_TYPES))],
            'ai_stems_suno_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_music_max_seconds' => ['nullable', 'integer', 'between:10,300'],
            'ai_lyrics_credits' => ['nullable', 'integer', 'between:1,1000'],
            'ai_lyrics_auto' => ['nullable', 'boolean'],
            'ai_lyrics_explicit_words' => ['nullable', 'string', 'max:2000'],
            'ai_lyrics_review_words' => ['nullable', 'string', 'max:2000'],
            'ai_tags_auto' => ['nullable', 'boolean'],
            'ai_moderator_claims_auto' => ['nullable', 'boolean'],
            'ai_moderator_releases_auto' => ['nullable', 'boolean'],
            'ai_moderator_confidence' => ['nullable', 'integer', 'between:0,100'],
        ], [
            'ai_credit_packs.regex' => 'List pack sizes like "100, 500:10, 2000:20".',
        ]);

        AiSettings::update($valid);

        return $this->settings($this->savedMessage(), HttpStatus::ACCEPTED);
    }

    /**
     * Say which chosen engines still need their key in `.env`.
     */
    protected function savedMessage(): string
    {
        $missing = collect([AiEngine::REPLICATE, AiSettings::musicModel()->engine(), AiSettings::stemsEngine()])
            ->unique(fn(AiEngine $engine) => $engine->value)
            ->reject(fn(AiEngine $engine) => $engine->available())
            ->map(fn(AiEngine $engine) => $engine->missingKeys());

        return $missing->isEmpty()
            ? 'AI settings saved.'
            : 'Saved. Set ' . $missing->join(' and ') . ' in .env for the tools that use them.';
    }

    protected function settings(string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        $settings = [
            'fields' => AiSettings::forAdmin(),
            'keys' => AiSettings::keys(),
            'client' => AiSettings::clientConfig(),
            'models' => AiSettings::musicModels(),
            'options' => [
                'sunoVersions' => SunoMusic::VERSIONS,
                'sunoStemTypes' => AiSettings::SUNO_STEM_TYPES,
                'engines' => collect(AiEngine::cases())
                    ->mapWithKeys(fn(AiEngine $engine) => [$engine->value => $engine->label()])
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
