<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ActionResource;
use App\Http\Resources\FreesoundConnectionResource;
use App\Http\Resources\FreesoundSoundCollection;
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

        $return = (string) $request->input('return');
        $state = Str::random(40);

        Cache::put("freesound:state:{$state}", [
            'user_id' => $request->user()->id,
            // Only a path in our own app, so the callback can't be used to redirect elsewhere.
            'return' => str_starts_with($return, '/') && ! str_starts_with($return, '//') ? $return : null,
        ], now()->addMinutes(15));

        $result = ['url' => Freesound::authorizeUrl($state)];

        return (new ActionResource($result))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
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

        abort_unless(
            $saved && (int) $saved['user_id'] === (int) $request->user()->id,
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            'That sign-in link has expired. Try connecting Freesound again.'
        );

        try {
            $connection = Freesound::connect($request->user(), $valid['code']);
        } catch (RuntimeException $e) {
            abort(HttpStatus::UNPROCESSABLE_ENTITY->value, $e->getMessage());
        }

        return (new FreesoundConnectionResource($connection))
            ->additional([
                'return' => $saved['return'] ?? null,
                'message' => 'Freesound is connected.',
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ])
            ->response()
            ->setStatusCode(HttpStatus::OK->value);
    }

    public function disconnect(Request $request)
    {
        $connection = $request->user()->freesound()->first();
        $connection?->delete();

        return (new FreesoundConnectionResource($connection))
            ->additional([
                'message' => 'Freesound is disconnected.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
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

        $page = (int) ($valid['page'] ?? 1);

        try {
            $found = Freesound::search((string) ($valid['query'] ?? ''), $page, (int) ($valid['limit'] ?? 20));
        } catch (RuntimeException $e) {
            abort(HttpStatus::BAD_GATEWAY->value, $e->getMessage());
        }

        $ids = collect($found['results'])->pluck('id');

        $imported = Sound::query()
            ->where('user_id', $request->user()->id)
            ->where('source', 'freesound')
            ->whereIn('source_id', $ids)
            ->pluck('source_id')
            ->all();

        $results = collect($found['results'])
            ->map(fn($s) => [...$s, 'imported' => in_array($s['id'], $imported, true)])
            ->all();

        return (new FreesoundSoundCollection($results))->additional([
            'meta' => ['total' => $found['count'], 'page' => $page],
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Download a CC0 sound with the person's own Freesound account and add it to My sounds.
     */
    public function import(Request $request)
    {
        $valid = $request->validate(['id' => ['required', 'string', 'max:20', 'regex:/^\d+$/']]);
        $user = $request->user();

        $existing = Sound::query()
            ->where('user_id', $user->id)
            ->where('source', 'freesound')
            ->where('source_id', $valid['id'])
            ->first();

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
            throw ValidationException::withMessages(['connect' => 'Connect Freesound first.']);
        }

        try {
            $sound = Freesound::sound($valid['id']);
        } catch (RuntimeException $e) {
            abort(HttpStatus::BAD_GATEWAY->value, $e->getMessage());
        }

        abort_unless($sound['cc0'], HttpStatus::UNPROCESSABLE_ENTITY->value, 'Only CC0 sounds can be imported, so they can be used in anything you sell.');

        Limits::for($user)->ensureRoomFor((int) $sound['size'], 'id');

        try {
            $download = Freesound::download($sound, $connection);
            // The real size may differ from what Freesound reported.
            Limits::for($user)->ensureRoomFor($download['size'], 'id');
        } catch (RuntimeException $e) {
            abort(HttpStatus::BAD_GATEWAY->value, $e->getMessage());
        } catch (ValidationException $e) {
            if (isset($download)) {
                @unlink($download['path']);
            }

            throw $e;
        }

        $attributes = SoundLibrary::freesoundAttributes($sound);
        $stored = SoundLibrary::storeDownload($download, $attributes, $user);

        return (new SoundResource($stored))
            ->additional([
                'storage' => Limits::for($user)->storage(),
                'message' => 'Imported to My sounds.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    protected function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->checkPermissionTo('manage-configuration');
    }
}
