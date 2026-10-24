<?php

namespace App\Http\Controllers;

use App\Broadcasting\Channels;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\ProjectTrackStemResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackVersion;
use App\Models\Sound;
use App\Services\Limits;
use App\Services\Realtime\Realtime;
use App\Services\Sounds\SoundLibrary;
use App\Services\Sounds\SoundSettings;
use App\Services\Studio\TrackHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The studio's sounds: the shared library and each person's own sounds.
 */
class SoundController extends Controller
{
    /** The largest single sound file, in kilobytes */
    public const MAX_FILE_KB = 51200;

    /**
     * What the sounds panel can show, and how much storage is used.
     */
    public function status(Request $request)
    {
        $user = $request->user();
        $connection = $user->freesound()->first();

        return Providers::response()->success([
            'data' => [
                ...SoundSettings::clientConfig(),
                'freesoundConnected' => (bool) $connection,
                'freesoundUsername' => $connection?->username,
                'storage' => Limits::for($user)->storage(),
                'categories' => $this->categories(),
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * Sounds in the shared library.
     */
    public function library(Request $request)
    {
        abort_unless(SoundSettings::libraryEnabled(), HttpStatus::NOT_FOUND->value, 'The sound library is turned off.');

        return $this->list($request, Sound::query()->library()->active());
    }

    /**
     * The signed-in person's own sounds.
     */
    public function index(Request $request)
    {
        return $this->list($request, Sound::query()->where('user_id', $request->user()->id));
    }

    /**
     * Upload a sound to My sounds.
     */
    public function store(Request $request)
    {
        $valid = $request->validate([
            'file' => ['required', 'file', $this->audioRule(), 'max:'.self::MAX_FILE_KB],
            'name' => ['nullable', 'string', 'max:180'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:3600'],
            'bpm' => ['nullable', 'numeric', 'between:20,300'],
            'waveform' => ['nullable'],
        ]);

        Limits::for($request->user())->ensureRoomFor((int) $request->file('file')->getSize());

        $sound = SoundLibrary::store($request->file('file'), [
            'name' => $valid['name'] ?? str($request->file('file')->getClientOriginalName())->beforeLast('.')->limit(180, '')->toString(),
            'duration' => (float) ($valid['duration'] ?? 0),
            'bpm' => $valid['bpm'] ?? null,
            'waveform' => $this->waveform($request->input('waveform')),
        ], $request->user());

        return Providers::response()->success([
            'data' => SoundLibrary::present($sound),
            'storage' => Limits::for($request->user())->storage(),
            'message' => 'Sound added to My sounds.',
        ], HttpStatus::CREATED);
    }

    /**
     * Rename one of your sounds.
     */
    public function update(Request $request, Sound $sound)
    {
        $this->ensureOwn($request, $sound);

        $sound->update($request->validate([
            'name' => ['required', 'string', 'max:180'],
            'bpm' => ['nullable', 'numeric', 'between:20,300'],
        ]));

        return Providers::response()->success([
            'data' => SoundLibrary::present($sound),
            'message' => 'Sound updated.',
        ], HttpStatus::ACCEPTED);
    }

    public function destroy(Request $request, Sound $sound)
    {
        $this->ensureOwn($request, $sound);

        $sound->delete();

        return Providers::response()->success([
            'data' => ['id' => $sound->id],
            'storage' => Limits::for($request->user())->storage(),
            'message' => 'Sound removed.',
        ], HttpStatus::ACCEPTED);
    }

    /**
     * Add a library sound, or one of your own, to a track as a new stem.
     */
    public function addToTrack(Request $request, Project $project, ProjectTrack $track)
    {
        $user = $request->user();

        abort_unless(
            (int) $track->project_id === (int) $project->id &&
            ((int) $project->user_id === (int) $user->id || $project->isCollaber($user)),
            HttpStatus::FORBIDDEN->value,
            'Only the owner and collaborators can add to this track.'
        );

        $valid = $request->validate([
            'sound_id' => ['required', 'integer'],
            'start' => ['nullable', 'numeric', 'min:0', 'max:36000'],
        ]);

        $sound = Sound::query()->active()->whereKey($valid['sound_id'])->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id);
            if (SoundSettings::libraryEnabled()) {
                $q->orWhereNull('user_id');
            }
        })->first();

        abort_unless($sound, HttpStatus::NOT_FOUND->value, 'That sound isn\'t available.');

        $history = TrackHistory::for($track);
        $history->baseline();

        $stem = SoundLibrary::addToTrack($sound, $track, $user, (float) ($valid['start'] ?? 0));

        $history->record($user, ProjectTrackVersion::ADDED);

        Realtime::send(Channels::projectTrack($track), 'stem.added', [
            'track_id' => $track->id,
            'stem_id' => $stem->id,
        ]);

        return (new ProjectTrackStemResource($stem))->additional([
            'message' => 'Sound added to the track.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * @param  Builder<Sound>  $query
     */
    protected function list(Request $request, Builder $query)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $sounds = $query
            ->search($request->input('search'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->latest('id')
            ->paginate((int) $request->input('limit', 30));

        return Providers::response()->success([
            'data' => collect($sounds->items())->map(fn (Sound $s) => SoundLibrary::present($s))->all(),
            'meta' => [
                'total' => $sounds->total(),
                'page' => $sounds->currentPage(),
                'lastPage' => $sounds->lastPage(),
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function categories(): array
    {
        if (! SoundSettings::libraryEnabled()) {
            return [];
        }

        return Sound::query()->library()->active()->whereNotNull('category')
            ->distinct()->orderBy('category')->limit(50)->pluck('category')->all();
    }

    protected function ensureOwn(Request $request, Sound $sound): void
    {
        abort_unless((int) $sound->user_id === (int) $request->user()->id, HttpStatus::NOT_FOUND->value, 'Sound not found.');
    }

    public static function audioRule(): string
    {
        return str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();
    }

    /**
     * Loudness bars sent with an upload, as JSON or an array.
     *
     * @return array<int, float>|null
     */
    public static function waveform(mixed $input): ?array
    {
        $bars = is_string($input) ? json_decode($input, true) : $input;

        if (! is_array($bars)) {
            return null;
        }

        return collect($bars)->take(200)->map(fn ($v) => round(max(0, min(1, (float) $v)), 3))->values()->all();
    }
}
