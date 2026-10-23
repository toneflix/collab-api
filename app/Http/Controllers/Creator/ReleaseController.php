<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReleaseCollection;
use App\Http\Resources\ReleaseResource;
use App\Models\Release;
use App\Services\Reputation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Toneflix\LaravelFileable\Media;

class ReleaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $this->user->releases()->getQuery();

        $releases = $query->paginate($request->input('limit', 15));

        return (new ReleaseCollection($releases))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->merge(['release_date', $request->input('releaseDate', now())]);

        $this->validate($request, [
            'slug' => ['nullable', 'unique:releases,slug'],
            'isrc' => ['nullable', 'string'],
            'iswc' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'pline' => ['nullable', 'string'],
            'draft' => ['nullable', 'boolean'],
            'artwork' => ['nullable', 'image', 'mimes:png,jpg'],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'explicit' => ['nullable', 'boolean'],
            'composer' => ['nullable', 'string'],
            'publisher' => ['nullable', 'string'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'description' => ['nullable', 'string'],
            'release_date' => ['nullable', 'string'],
            'record_label' => ['nullable', 'string'],
            'contains_music' => ['nullable', 'boolean'],
        ], [
            'artist_id' => 'Artist not found.',
            'project_id' => 'Project not found.',
        ], [
            'genre' => 'genre',
            'artist_id' => 'artist',
            'release_date' => 'release date',
            'record_label' => 'record label',
            'contains_music' => 'contains music',
        ]);

        if ($request->has('project_id')) {
            /** @var \App\Models\Release $release */
            $release = $this->user->releases()->whereProjectId($request->project_id)->first();
        }

        $release ??= $this->user->releases()->make();

        $release->slug = $request->input('slug');
        $release->isrc = $request->input('isrc', '');
        $release->iswc = $request->input('iswc', '');
        $release->title = $request->input('title');
        $release->pline = $request->input('pline', '');
        $release->draft = $request->boolean('draft', true);
        $release->privacy = $request->input('privacy', 'public');
        $release->explicit = $request->boolean('explicit', false);
        $release->composer = $request->input('composer');
        $release->genre_id = $request->input('genre_id');
        $release->publisher = $request->input('publisher');
        $release->artist_id = $request->input('artist_id');
        $release->project_id = $request->input('project_id');
        $release->description = $request->input('description', '');
        $release->record_label = $request->input('record_label', '');
        $release->release_date = $request->input('release_date');
        $release->contains_music = $request->boolean('contains_music', true);
        $release->save();

        $this->saveTracks($request, $release);

        return (new ReleaseResource($release))->additional([
            'message' => 'New release created successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        /** @var \App\Models\Release $release */
        $release = $this->user->releases()
            ->where('slug', $id)->when(is_numeric($id), fn ($q) => $q->orWhere('releases.id', $id))
            ->firstOrFail();

        $request->merge(['release_date', $request->input('releaseDate', now())]);

        $this->validate($request, [
            'slug' => ['nullable', "unique:releases,slug,$id,id"],
            'isrc' => ['nullable', 'string'],
            'iswc' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'pline' => ['nullable', 'string'],
            'draft' => ['nullable', 'boolean'],
            'tracks' => ['nullable', 'array'],
            'publish' => ['nullable', 'boolean'],
            'artwork' => ['image', 'mimes:png,jpg'],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'explicit' => ['nullable', 'boolean'],
            'composer' => ['nullable', 'string'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'publisher' => ['nullable', 'string'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'description' => ['nullable', 'string'],
            'record_label' => ['nullable', 'string'],
            'release_date' => ['nullable', 'string'],
            'contains_music' => ['nullable', 'boolean'],
        ], [
            'artist_id' => 'Artist not found.',
            'project_id' => 'Project not found.',
        ], [
            'genre' => 'genre',
            'artist_id' => 'artist',
            'release_date' => 'release date',
            'record_label' => 'record label',
            'contains_music' => 'contains music',
        ]);

        $this->saveTracks($request, $release);

        if ($release->slug) {
            $release->slug = $request->input('slug', $release->slug);
        }

        $draft = $release->draft;

        $release->isrc = $request->input('isrc', '');
        $release->iswc = $request->input('iswc', '');
        $release->title = $request->input('title', $release->title);
        $release->pline = $request->input('pline', '');
        $release->draft = $request->boolean('draft', true);
        $release->privacy = $request->input('privacy', 'public');
        $release->explicit = $request->boolean('explicit', false);
        $release->composer = $request->input('composer');
        $release->genre_id = $request->input('genre_id', $release->genre_id);
        $release->publisher = $request->input('publisher');
        $release->artist_id = $request->input('artist_id', $release->artist_id);
        $release->project_id ??= $request->input('project_id');
        $release->description = $request->input('description', '');
        $release->record_label = $request->input('record_label', '');
        $release->release_date = $request->input('release_date', now());
        $release->contains_music ??= $request->boolean('contains_music', true);
        $release->save();

        // Add reputation for the owner
        if ($draft && !$release->draft && $release->project) {
            $repType = $this->user->projects()->released()->count() > 1 ? 'publish_project' : 'publish_first_project';
            (new Reputation($this->user, $repType))->add();
        }

        return (new ReleaseResource($release))->additional([
            'message' => __('Release :0 successfully.', [$request->publish ? 'published' : 'updated']),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    public function saveTracks(Request $request, Release $release): void
    {
        if (!$request->has('tracks')) {
            return;
        }

        ['tracks' => $tracks] = $this->validate($request, [
            'tracks.*.id' => ['nullable', 'required_without:tracks.*.project_track_id', 'exists:tracks,id'],
            'tracks.*.title' => ['required', 'string'],
            'tracks.*.image' => ['nullable', 'image', 'mimes:png,jpg'],
            'tracks.*.audio' => ['nullable', 'prohibited_unless:project_track_id,null'],
            'tracks.*.privacy' => ['nullable', 'string', 'in:public,private'],
            'tracks.*.duration' => ['nullable', 'numeric'],
            'tracks.*.genre_id' => ['required', 'string', 'exists:genres,id'],
            'tracks.*.artist_id' => ['required', 'string', 'exists:artists,id'],
            'tracks.*.description' => ['nullable', 'string'],
            'tracks.*.project_track_id' => ['nullable', 'exists:project_tracks,id'],
        ], [
            // 'tracks.*.id' => 'Invalid track.'
        ], [
            'tracks.*.id' => 'track',
            'tracks.*.title' => 'title',
            'tracks.*.image' => 'image',
            'tracks.*.audio' => 'audio',
            'tracks.*.privacy' => 'privacy',
            'tracks.*.duration' => 'duration',
            'tracks.*.genre_id' => 'genre',
            'tracks.*.artist_id' => 'artist',
            'tracks.*.project_track_id' => 'project track',
            'tracks.*.description' => 'description',
        ]);

        collect($tracks)->each(function ($form, $i) use ($release, $request) {
            if (isset($form['project_track_id']) && !isset($form['id'])) {
                /** @var \App\Models\Track $track */
                $track = $release->tracks()->firstOrNew(['project_track_id' => $form['project_track_id']], $form);
                $track->slug = $track->gusm($track->title, '-');
                $track->saveQuietly();
            } else {
                /** @var \App\Models\Track $track */
                $track = $release->tracks()->find($form['id']);
            }

            if ($track) {
                if (isset(Arr::dot($request->allFiles())["tracks.$i.image"])) {
                    $form['image'] = (new Media())->save('artwork', 'tracks.*.image', $track->image, $i);
                }

                if (isset(Arr::dot($request->allFiles())["tracks.$i.audio"]) && (!$track->exists || !$track->audio)) {
                    $form['audio'] = (new Media())->save('music_tracks', 'tracks.*.audio', $track->audio, $i);
                } else {
                    unset($form['audio']);
                }

                if ($track->exists) {
                    $track->updateQuietly($form);
                } else {
                    $track->saveQuietly();
                }

                $artists = [];

                // Update the track artist
                if (isset($form['artist_id'])) {
                    $artists[$form['artist_id']] = ['type' => 'primary'];
                    if ($track->projectTrack) {
                        $track->projectTrack->artist_id = $form['artist_id'];
                    }
                }

                // Update the related project track
                if ($track->projectTrack) {
                    $track->projectTrack->genre_id = $form['genre_id'];
                    $track->projectTrack->saveQuietly();
                }
                $track->artists()->sync($artists);
            }
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        /** @var \App\Models\Release $release */
        $release = $this->user->releases()
            ->where('slug', $id)->when(is_numeric($id), fn ($q) => $q->orWhere('releases.id', $id))
            ->firstOrFail();

        $release->delete();

        return (new ReleaseCollection([]))->additional([
            'message' => 'Release deleted successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}