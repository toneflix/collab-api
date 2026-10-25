<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SoundController;
use App\Http\Resources\SoundResource;
use App\Jobs\SyncSoundLibrary;
use App\Models\Sound;
use App\Services\Sounds\SoundLibrary;
use App\Services\Sounds\SoundSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Lets admins turn the sound library and Freesound on or off, sync the
 * library and manage its sounds. The Freesound keys come from `.env`.
 */
class SoundSettingsController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $request->user()?->checkPermissionTo('manage-configuration'),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to change sound settings.'
            );

            return $next($request);
        });
    }

    public function show(Request $request)
    {
        return $this->settings($request);
    }

    public function update(Request $request)
    {
        $valid = $request->validate([
            'sounds_library_enabled' => ['nullable', 'boolean'],
            'freesound_enabled' => ['nullable', 'boolean'],
            'freesound_sync_queries' => ['nullable', 'string', 'max:3000'],
            'freesound_sync_limit' => ['nullable', 'integer', 'between:1,150'],
            'freesound_sync_max_duration' => ['nullable', 'numeric', 'between:1,600'],
        ]);

        SoundSettings::update($valid);

        $message = filter_var($valid['freesound_enabled'] ?? false, FILTER_VALIDATE_BOOL) && ! SoundSettings::freesoundConfigured()
            ? 'Saved, but Freesound stays off until FREESOUND_CLIENT_ID and FREESOUND_CLIENT_SECRET are set in .env.'
            : 'Sound settings saved.';

        return $this->settings($request, $message, HttpStatus::ACCEPTED);
    }

    /**
     * Use the signed-in admin's connected Freesound account to download
     * original files for the library.
     */
    public function useAccount(Request $request)
    {
        $connection = $request->user()->freesound()->first();

        if (! $connection) {
            return Providers::response()->error([
                'message' => 'Connect your Freesound account first.',
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        SoundSettings::update(['freesound_library_account' => (string) $request->user()->id]);

        return $this->settings($request, 'The library now downloads original files with your Freesound account.', HttpStatus::ACCEPTED);
    }

    /**
     * Start a library sync in the background.
     */
    public function sync(Request $request)
    {
        if (! SoundSettings::freesoundConfigured()) {
            return Providers::response()->error(['message' => 'Set the Freesound keys in .env first.'], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        if (! SoundSettings::syncQueries()) {
            return Providers::response()->error(['message' => 'Add at least one sync search first.'], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        if (! Cache::add('sounds::sync-running', now()->toIso8601String(), 3600)) {
            return $this->settings($request, 'A sync is already running.', HttpStatus::ACCEPTED);
        }

        SyncSoundLibrary::dispatch();

        return $this->settings($request, 'Sync started. New sounds show up as they download.', HttpStatus::ACCEPTED);
    }

    /**
     * Sounds in the shared library, including hidden ones.
     */
    public function library(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $sounds = Sound::query()->library()
            ->search($request->input('search'))
            ->latest('id')
            ->paginate((int) $request->input('limit', 30));

        return SoundResource::collection($sounds)->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Upload a sound straight into the shared library.
     */
    public function store(Request $request)
    {
        $valid = $request->validate([
            'file' => ['required', 'file', SoundController::audioRule(), 'max:' . SoundController::MAX_FILE_KB],
            'name' => ['nullable', 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:100'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:3600'],
            'bpm' => ['nullable', 'numeric', 'between:20,300'],
            'license' => ['nullable', 'string', 'max:190'],
            'author' => ['nullable', 'string', 'max:190'],
            'waveform' => ['nullable'],
        ]);

        $sound = SoundLibrary::store($request->file('file'), [
            'name' => $valid['name'] ?? str($request->file('file')->getClientOriginalName())->beforeLast('.')->limit(180, '')->toString(),
            'category' => $valid['category'] ?? null,
            'duration' => (float) ($valid['duration'] ?? 0),
            'bpm' => $valid['bpm'] ?? null,
            'license' => $valid['license'] ?? null,
            'author' => $valid['author'] ?? null,
            'waveform' => SoundController::waveform($request->input('waveform')),
        ]);

        return (new SoundResource($sound))->additional([
            'message' => 'Sound added to the library.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    public function updateSound(Request $request, Sound $sound)
    {
        abort_unless($sound->user_id === null, HttpStatus::NOT_FOUND->value, 'Sound not found.');

        $sound->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bpm' => ['sometimes', 'nullable', 'numeric', 'between:20,300'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        SoundLibrary::forgetSize();

        return (new SoundResource($sound))->additional([
            'message' => 'Sound updated.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(Sound $sound)
    {
        abort_unless($sound->user_id === null, HttpStatus::NOT_FOUND->value, 'Sound not found.');

        $sound->delete();
        SoundLibrary::forgetSize();

        return Providers::response()->success([
            'data' => ['id' => $sound->id],
            'message' => 'Sound removed from the library.',
        ], HttpStatus::ACCEPTED);
    }

    protected function settings(Request $request, string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        $account = SoundSettings::get('freesound_library_account');
        $connection = SoundLibrary::libraryConnection();
        $mine = $request->user()->freesound()->first();

        return Providers::response()->success([
            'data' => [
                'fields' => collect(SoundSettings::forAdmin())->except(['freesound_library_account', 'freesound_last_sync'])->all(),
                'configured' => SoundSettings::freesoundConfigured(),
                'keys' => SoundSettings::keys(),
                'client' => SoundSettings::clientConfig(),
                'librarySize' => Sound::query()->library()->count(),
                'activeSize' => Sound::query()->library()->active()->count(),
                'syncing' => Cache::has('sounds::sync-running'),
                'lastSync' => json_decode((string) SoundSettings::get('freesound_last_sync'), true) ?: null,
                'downloads' => [
                    // Without a connected account the sync falls back to MP3 previews.
                    'quality' => $connection ? 'original' : 'preview',
                    'username' => $connection?->username,
                    'isMe' => $account && (int) $account === (int) $request->user()->id,
                ],
                'me' => ['connected' => (bool) $mine, 'username' => $mine?->username],
            ],
            'message' => $message,
        ], $status);
    }
}
