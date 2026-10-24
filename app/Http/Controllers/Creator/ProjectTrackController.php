<?php

namespace App\Http\Controllers\Creator;

use App\Broadcasting\Channels;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTrackCollection;
use App\Http\Resources\ProjectTrackResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackVersion;
use App\Services\Realtime\Realtime;
use App\Services\Reputation;
use App\Services\Studio\TrackHistory;
use Illuminate\Http\Request;
use Toneflix\LaravelFileable\Media;

class ProjectTrackController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project)
    {
        $query = $project->tracks()->getQuery();

        $tracks = $query->paginate($request->input('limit', 45));

        return (new ProjectTrackCollection($tracks))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'title' => ['required', 'string'],
            'preview' => ['nullable', 'file', $allowedAudio],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'description' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $track = $project->tracks()->create($valid);

        // Add reputation for the owner
        (new Reputation($this->user, 'create_project_track'))->add();

        return (new ProjectTrackResource($track))->additional([
            'message' => 'Project track created successfully',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, ProjectTrack $track)
    {
        return (new ProjectTrackResource($track))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'title' => ['required', 'string'],
            'preview' => ['nullable', 'file', $allowedAudio],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'description' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
            'stems' => ['nullable', 'array'],
            'bpm' => ['nullable', 'numeric', 'between:20,300'],
            'time_signature' => ['nullable', 'string', 'in:'.implode(',', ProjectTrack::TIME_SIGNATURES)],
        ]);

        // Tempo and meter live in the track's meta, next to whatever else is there.
        $meta = collect($track->meta ?? [])->merge($valid['meta'] ?? []);
        foreach (['bpm' => 'bpm', 'time_signature' => 'timeSignature'] as $field => $key) {
            if ($request->filled($field)) {
                $meta[$key] = $field === 'bpm' ? round((float) $valid[$field], 2) : $valid[$field];
            }
        }
        $valid['meta'] = $meta->all();
        unset($valid['bpm'], $valid['time_signature']);

        if (isset($valid['preview'])) {
            unset($valid['preview']);
        }

        $history = TrackHistory::for($track);
        $history->baseline();

        $this->saveStems($request, $track);
        $track->update($valid);

        $history->record($this->user, ProjectTrackVersion::SAVED);

        // Everyone else on the track now has this as the saved state. A
        // message too big for the driver carries no stems; clients reload.
        $stems = $track->stems()->get()->mapWithKeys(fn ($stem) => [
            $stem->id => collect($stem->meta)->except('src')->all(),
        ]);

        Realtime::send(Channels::projectTrack($track), 'track.saved', [
            'track_id' => $track->id,
            'title' => $track->title,
            'bpm' => (float) ($track->meta['bpm'] ?? 120),
            'timeSignature' => (string) ($track->meta['timeSignature'] ?? '4/4'),
            'stems' => strlen((string) json_encode($stems)) > 8000 ? null : $stems->all(),
        ]);

        return (new ProjectTrackResource($track))->additional([
            'message' => 'Project track updated successfully',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    protected function saveStems(Request $request, ProjectTrack $track): void
    {
        if (! $request->has('stems')) {
            return;
        }

        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        ['stems' => $stems] = $this->validate($request, [
            'stems.*.id' => ['required', 'exists:project_track_stems,id'],
            'stems.*.src' => ['nullable', 'file', $allowedAudio],
            'stems.*.meta' => ['required', 'array'],
            'stems.*.stem_id' => ['nullable', 'exists:project_track_stems,id'],
        ], [
            'stems.*.id' => 'Invalid track stem.',
        ], [
            'stems.*.id' => 'stem',
            'stems.*.src' => 'file',
            'stems.*.meta' => 'meta data',
            'stems.*.stem_id' => 'parent',
        ]);

        collect($stems)->each(function ($form, $i) use ($track) {
            /** @var ProjectTrack $stem */
            $stem = $track->stems()->find($form['id']);

            // Add reputation for the owner
            (new Reputation($this->user, 'create_project_track_stem'))->add();

            if ($stem) {
                $form['src'] = (new Media)->save('stem_files', 'stems.*.src', $stem->src, $i);
                $stem->updateQuietly($form);
            }
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, ProjectTrack $track)
    {
        $this->authorize('delete', $track);

        $track->delete();

        return (new ProjectTrackResource($track))->additional([
            'message' => 'Project track deleted successfully',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
