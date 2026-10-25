<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\SoundResource;
use App\Models\Sound;
use App\Services\Limits;
use App\Services\Sounds\Freesound;
use App\Services\Sounds\SoundLibrary;
use App\Services\Sounds\SoundSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Connect Freesound, search it and import CC0 sounds into My sounds.
 */
class FreesoundController extends Controller
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                SoundSettings::freesoundConfigured() && (SoundSettings::freesoundEnabled() || $this->isAdmin($request)),
                HttpStatus::NOT_FOUND->value,
                'Freesound is turned off.'
            );

            return $next($request);
        });
    }

    /**
     * The Freesound page to send someone to, to connect their account.
     */
    public function connect(Request $request)
    {
        $request->validate(['return' => ['nullable', 'string', 'max:300']]);

        $state = Str::random(40);
        Cache::put("freesound:state:{$state}", [
            'user_id' => $request->user()->id,
            // Only a path in our own app, so the callback can't be used to redirect elsewhere.
            'return' => str_starts_with((string) $request->input('return'), '/') && ! str_starts_with((string) $request->input('return'), '//')
                ? $request->input('return')
                : null,
        ], now()->addMinutes(15));

        return Providers::response()->success([
            'data' => ['url' => Freesound::authorizeUrl($state)],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * Finish connecting with the code Freesound sent back.
     */
    public function callback(Request $request)
    {
        $valid = $request->validate([
            'code' => ['required', 'string', 'max:200'],
            'state' => ['required', 'string', 'max:100'],
        ]);

        $saved = Cache::pull("freesound:state:{$valid['state']}");

        if (! $saved || (int) $saved['user_id'] !== (int) $request->user()->id) {
            return Providers::response()->error([
                'message' => 'That sign-in link has expired. Try connecting Freesound again.',
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        try {
            $connection = Freesound::connect($request->user(), $valid['code']);
        } catch (RuntimeException $e) {
            return Providers::response()->error(['message' => $e->getMessage()], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        return Providers::response()->success([
            'data' => ['username' => $connection->username, 'return' => $saved['return'] ?? null],
            'message' => 'Freesound is connected.',
        ]);
    }

    public function disconnect(Request $request)
    {
        $request->user()->freesound()->delete();

        return Providers::response()->success([
            'data' => ['connected' => false],
            'message' => 'Freesound is disconnected.',
        ], HttpStatus::ACCEPTED);
    }

    /**
     * Search Freesound. Only CC0 sounds are returned.
     */
    public function search(Request $request)
    {
        $valid = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'between:1,50'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        try {
            $found = Freesound::search((string) ($valid['query'] ?? ''), (int) ($valid['page'] ?? 1), (int) ($valid['limit'] ?? 20));
        } catch (RuntimeException $e) {
            return Providers::response()->error(['message' => $e->getMessage()], HttpStatus::BAD_GATEWAY);
        }

        $imported = Sound::query()->where('user_id', $request->user()->id)->where('source', 'freesound')
            ->whereIn('source_id', collect($found['results'])->pluck('id'))->pluck('source_id')->all();

        return Providers::response()->success([
            'data' => collect($found['results'])->map(fn($s) => [...$s, 'imported' => in_array($s['id'], $imported, true)])->all(),
            'meta' => ['total' => $found['count'], 'page' => (int) ($valid['page'] ?? 1)],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * Download a CC0 sound with the person's own Freesound account and add it to My sounds.
     */
    public function import(Request $request)
    {
        $valid = $request->validate(['id' => ['required', 'string', 'max:20', 'regex:/^\d+$/']]);
        $user = $request->user();

        $existing = Sound::query()->where('user_id', $user->id)->where('source', 'freesound')->where('source_id', $valid['id'])->first();
        if ($existing) {
            return (new SoundResource($existing))->additional([
                'storage' => Limits::for($user)->storage(),
                'message' => 'That sound is already in My sounds.',
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
        }

        $connection = $user->freesound()->first();
        if (! $connection) {
            return Providers::response()->error([
                'message' => 'Connect your Freesound account to import sounds.',
                'errors' => ['connect' => ['Connect Freesound first.']],
            ], HttpStatus::UNPROCESSABLE_ENTITY);
        }

        try {
            $sound = Freesound::sound($valid['id']);

            if (! $sound['cc0']) {
                return Providers::response()->error([
                    'message' => 'Only CC0 sounds can be imported, so they can be used in anything you sell.',
                ], HttpStatus::UNPROCESSABLE_ENTITY);
            }

            Limits::for($user)->ensureRoomFor((int) $sound['size'], 'id');

            $download = Freesound::download($sound, $connection);
            // The real size may differ from what Freesound reported.
            Limits::for($user)->ensureRoomFor($download['size'], 'id');
        } catch (RuntimeException $e) {
            return Providers::response()->error(['message' => $e->getMessage()], HttpStatus::BAD_GATEWAY);
        } catch (ValidationException $e) {
            if (isset($download)) {
                @unlink($download['path']);
            }
            throw $e;
        }

        $stored = SoundLibrary::storeDownload($download, SoundLibrary::freesoundAttributes($sound), $user);

        return (new SoundResource($stored))->additional([
            'storage' => Limits::for($user)->storage(),
            'message' => 'Imported to My sounds.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    protected function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->checkPermissionTo('manage-configuration');
    }
}
