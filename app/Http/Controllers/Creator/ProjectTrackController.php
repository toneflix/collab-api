<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectTrackCollection;
use App\Http\Resources\ProjectTrackResource;
use App\Models\Project;
use App\Models\ProjectTrack;
use Illuminate\Http\Request;
use ToneflixCode\LaravelFileable\Media;

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

        return (new ProjectTrackResource($track))->additional([
            'message' => 'Project track created successfully',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
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
        ]);

        if (isset($valid['preview'])) {
            unset($valid['preview']);
        }

        $this->saveStems($request, $track);
        $track->update($valid);

        return (new ProjectTrackResource($track))->additional([
            'message' => 'Project track updated successfully',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
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
            /** @var \App\Models\ProjectTrack $stem */
            $stem = $track->stems()->find($form['id']);

            if ($stem) {
                $form['src'] = (new Media())->save('stem_files', 'stems.*.src', $stem->src, $i);
                $stem->updateQuietly($form);
            }
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project, ProjectTrack $track)
    {
        $track->delete();

        return (new ProjectTrackResource($track))->additional([
            'message' => 'Project track deleted successfully',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }
}
