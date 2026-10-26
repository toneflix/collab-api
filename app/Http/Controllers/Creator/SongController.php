<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Enums\MusicalKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongCollection;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Services\Composer\SongProjects;
use App\Services\Composer\Songs;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The writer's Composer songs.
 */
class SongController extends Controller
{
    public function index(Request $request)
    {
        $songs = $this->user
            ->songs()
            ->search($request->input('search'))
            ->latest('updated_at')
            ->latest('id')
            ->paginate($request->perPage(20));

        return (new SongCollection($songs))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function store(Request $request, Songs $songs)
    {
        $data = $request->validate($this->rules($request));

        $song = $songs->create($this->user, $data);
        $song->load('beat');

        return (new SongResource($song))
            ->additional([
                'message' => 'Composition created.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function show(Song $song)
    {
        $this->authorize('view', $song);

        $song->load(['beat', 'track.project', 'forkedFrom']);

        return (new SongResource($song))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Autosave: saves the fields sent, from the revision the editor last loaded.
     */
    public function update(Request $request, Songs $songs, SongProjects $projects, Song $song)
    {
        $this->authorize('update', $song);

        $data = $request->validate($this->rules($request) + [
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        $lines = $song->lyricLines();
        $song = $songs->save($song, $this->user, $data, (int) $data['revision']);
        $song->load(['beat', 'track.project']);

        // A song made into a project keeps its track's lyric sheet in step.
        $synced = $song->track
            && $song->lyricLines() !== $lines
            && $this->user->can('update', $song->track);

        if ($synced) {
            $projects->syncLyrics($song, $this->user);
        }

        return (new SongResource($song))
            ->additional([
                'message' => $synced ? 'Composition saved and its lyric sheet updated.' : 'Composition saved.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function destroy(Song $song)
    {
        $this->authorize('delete', $song);

        $song->delete();

        return (new SongResource($song))
            ->additional([
                'message' => 'Composition deleted.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(Request $request): array
    {
        $userId = $this->user->id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'scratchpad' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'bpm' => ['sometimes', 'nullable', 'integer', 'between:30,300'],
            'key' => ['sometimes', 'nullable', Rule::enum(MusicalKey::class)],
            'beat_sound_id' => [
                'sometimes',
                'nullable',
                Rule::exists('sounds', 'id')->where(fn(Builder $q) => $q
                    ->whereNull('user_id')
                    ->orWhere('user_id', $userId)),
            ],
            'settings' => ['sometimes', 'array'],
            'settings.auto_suggest' => ['sometimes', 'boolean'],
            'settings.rhyme_colours' => ['sometimes', 'boolean'],
            'settings.mood' => ['sometimes', 'nullable', 'string', 'max:60'],
            'settings.language' => ['sometimes', 'nullable', 'string', 'max:60'],
            'settings.explicit' => ['sometimes', 'boolean'],
        ];
    }
}
