<?php

namespace App\Http\Controllers\Creator;

use App\Broadcasting\Channels;
use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTrackStemCollection;
use App\Http\Resources\ProjectTrackStemResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\ProjectTrackVersion;
use App\Services\Limits;
use App\Services\Realtime\Realtime;
use App\Services\Reputation;
use App\Services\Studio\StemFiles;
use App\Services\Studio\TrackHistory;
use Illuminate\Http\Request;

class ProjectTrackStemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Project $project, ProjectTrack $track)
    {
        $this->authorize('view', $track);

        $query = $track
            ->stems()
            ->getQuery();

        $stems = $query->get();

        return (new ProjectTrackStemCollection($stems))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project, ProjectTrack $track)
    {
        $this->authorize('update', $track);

        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'src' => array_filter(['nullable', 'file', $allowedAudio, Limits::for($this->user)->stemSizeRule()]),
            'meta' => ['nullable', 'array'],
            'title' => ['nullable', 'string'],
            'stem_id' => ['nullable', 'string', 'exists:project_track_stems,id'],
        ]);

        $valid['user_id'] = $request->user()->id;

        $history = TrackHistory::for($track);
        $history->baseline();

        $stem = $track
            ->stems()
            ->create($valid);

        $history->record($request->user(), ProjectTrackVersion::ADDED);

        // Add reputation for the owner
        (new Reputation($this->user, 'create_project_track_stem'))->add();

        // Others fetch the stem themselves, since its audio link is per user.
        Realtime::send(Channels::projectTrack($track), 'stem.added', [
            'track_id' => $track->id,
            'stem_id' => $stem->id,
        ]);

        return (new ProjectTrackStemResource($stem))
            ->additional([
                'message' => 'Track stem created successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Copy a stem, audio included, onto its track.
     */
    public function duplicate(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem, StemFiles $files)
    {
        $this->authorize('update', $track);

        $meta = $this->validate($request, [
            'meta' => ['nullable', 'array'],
        ])['meta'] ?? [];

        $history = TrackHistory::for($track);
        $history->baseline();

        $copy = $files->duplicate($stem, $request->user(), $meta);

        $history->record($request->user(), ProjectTrackVersion::ADDED);

        Realtime::send(Channels::projectTrack($track), 'stem.added', [
            'track_id' => $track->id,
            'stem_id' => $copy->id,
        ]);

        return (new ProjectTrackStemResource($copy))
            ->additional([
                'message' => 'Stem duplicated.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Save the stem as heard through its plugins, for the mix to use in their place.
     */
    public function print(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem, StemFiles $files)
    {
        $this->authorize('update', $track);

        $valid = $this->validate($request, [
            'audio' => ['required', 'file', 'mimes:wav', 'max:' . (config('studio.print_max_mb') * 1024)],
            'start' => ['required', 'numeric', 'min:0'],
            'key' => ['required', 'string', 'max:64'],
        ]);

        $audio = $request->file('audio');

        $files->print($stem, $audio->getRealPath(), 'wav', (float) $valid['start'], $valid['key']);

        return (new ProjectTrackStemResource($stem))
            ->additional([
                'message' => 'Stem printed.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $this->authorize('view', $track);

        return (new ProjectTrackStemResource($stem))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $this->authorize('update', $track);

        $allowedAudio = str(collect(Providers::config('allowed_audio_exts'))->join(','))
            ->remove('.')
            ->prepend('mimes:')
            ->toString();

        $valid = $this->validate($request, [
            'src' => array_filter(['nullable', 'file', $allowedAudio, Limits::for($this->user)->stemSizeRule()]),
            'meta' => ['required', 'array'],
            'title' => ['nullable', 'string'],
            'stem_id' => ['nullable', 'string', 'exists:project_track_stems,id'],
        ]);

        $valid['user_id'] = $request->user()->id;
        $valid['meta'] = $stem->withPrint($valid['meta']);

        $history = TrackHistory::for($track);
        $history->baseline();

        $stem->update($valid);

        $history->record($request->user(), ProjectTrackVersion::EDITED);

        Realtime::send(Channels::projectTrack($track), 'stem.updated', [
            'track_id' => $track->id,
            'stem_id' => $stem->id,
            'title' => $stem->title,
            'meta' => collect($stem->meta)->except('src')->all(),
        ]);

        return (new ProjectTrackStemResource($stem))
            ->additional([
                'message' => 'Track stem updated successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, ProjectTrack $track, ProjectTrackStem $stem)
    {
        $this->authorize('update', $track);

        $history = TrackHistory::for($track);
        $history->baseline();

        $stem->delete();

        $history->record($request->user(), ProjectTrackVersion::REMOVED);

        Realtime::send(Channels::projectTrack($track), 'stem.removed', [
            'track_id' => $track->id,
            'stem_id' => $stem->id,
        ]);

        return (new ProjectTrackStemCollection([]))
            ->additional([
                'message' => 'Track stem deleted successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
