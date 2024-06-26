<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReleaseCollection;
use App\Http\Resources\ReleaseResource;
use App\Models\Release;
use Illuminate\Http\Request;
use ToneflixCode\LaravelFileable\Media;

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
            'title' => ['required', 'string'],
            'artwork' => ['nullable', 'image', 'mimes:png,jpg'],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'description' => ['nullable', 'string'],
            'isrc' => ['nullable', 'string'],
            'iswc' => ['nullable', 'string'],
            'pline' => ['nullable', 'string'],
            'record_label' => ['nullable', 'string'],
            'draft' => ['nullable', 'boolean'],
            'explicit' => ['nullable', 'boolean'],
            'composer' => ['nullable', 'string'],
            'publisher' => ['nullable', 'string'],
            'contains_music' => ['nullable', 'boolean'],
            'release_date' => ['nullable', 'string'],
        ], [
            'artist_id' => 'Artist not found.'
        ], [
            'genre' => 'genre',
            'artist_id' => 'artist',
            'release_date' => 'release date',
            'record_label' => 'record label',
            'contains_music' => 'contains music',
        ]);

        $release = $this->user->releases()->make();
        $release->slug = $request->input('slug');
        $release->title = $request->input('title');
        $release->genre_id = $request->input('genre_id');
        $release->artist_id = $request->input('artist_id');
        $release->privacy = $request->input('privacy', 'public');
        $release->description = $request->input('description', '');
        $release->isrc = $request->input('isrc', '');
        $release->iswc = $request->input('iswc', '');
        $release->pline = $request->input('pline', '');
        $release->record_label = $request->input('record_label', '');
        $release->explicit = $request->boolean('explicit', false);
        $release->draft = $request->boolean('draft', true);
        $release->composer = $request->input('composer');
        $release->publisher = $request->input('publisher');
        $release->contains_music = $request->boolean('contains_music', true);
        $release->release_date = $request->input('release_date');
        $release->save();

        return (new ReleaseResource($release))->additional([
            'message' => "New release created successfully.",
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ]);
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
        $release = $this->user->releases()
            ->whereId($id)
            ->orWhere('slug', $id)
            ->firstOrFail();

        $request->merge(['release_date', $request->input('releaseDate', now())]);

        $this->validate($request, [
            'slug' => ['nullable', "unique:releases,slug,$id,id"],
            'title' => ['required', 'string'],
            'artwork' => ['image', 'mimes:png,jpg'],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'description' => ['nullable', 'string'],
            'isrc' => ['nullable', 'string'],
            'iswc' => ['nullable', 'string'],
            'pline' => ['nullable', 'string'],
            'record_label' => ['nullable', 'string'],
            'draft' => ['nullable', 'boolean'],
            'publish' => ['nullable', 'boolean'],
            'explicit' => ['nullable', 'boolean'],
            'composer' => ['nullable', 'string'],
            'publisher' => ['nullable', 'string'],
            'contains_music' => ['nullable', 'boolean'],
            'release_date' => ['nullable', 'string'],
            'tracks' => ['nullable', 'array']
        ], [
            'artist_id' => 'Artist not found.'
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
        $release->title = $request->input('title', $release->title);
        $release->genre_id = $request->input('genre_id', $release->genre_id);
        $release->artist_id = $request->input('artist_id', $release->artist_id);
        $release->privacy = $request->input('privacy', 'public');
        $release->description = $request->input('description', '');
        $release->isrc = $request->input('isrc', '');
        $release->iswc = $request->input('iswc', '');
        $release->pline = $request->input('pline', '');
        $release->record_label = $request->input('record_label', '');
        $release->explicit = $request->boolean('explicit', false);
        $release->draft = $request->boolean('draft', true);
        $release->composer = $request->input('composer');
        $release->publisher = $request->input('publisher');
        $release->contains_music ??= $request->boolean('contains_music', true);
        $release->release_date = $request->input('release_date', now());
        $release->save();

        return (new ReleaseResource($release))->additional([
            'message' => __("Release :0 successfully.", [$request->publish ? 'published' : 'updated']),
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }

    public function saveTracks(Request $request, Release $release): void
    {
        if (!$request->has('tracks')) {
            return;
        }

        ['tracks' => $tracks] = $this->validate($request, [
            'tracks.*.id' => ['required', 'exists:tracks,id'],
            'tracks.*.title' => ['required', 'string'],
            'tracks.*.image' => ['nullable', 'image', 'mimes:png,jpg'],
            'tracks.*.audio' => ['nullable', 'prohibited'],
            'tracks.*.privacy' => ['nullable', 'string', 'in:public,private'],
            'tracks.*.genre_id' => ['required', 'string', 'exists:genres,id'],
            'tracks.*.artist_id' => ['required', 'string', 'exists:artists,id'],
            'tracks.*.description' => ['nullable', 'string'],
        ], [
            'tracks.*.id' => 'Invalid track.'
        ], [
            'tracks.*.id' => 'track',
            'tracks.*.title' => 'title',
            'tracks.*.image' => 'image',
            'tracks.*.audio' => 'audio',
            'tracks.*.privacy' => 'privacy',
            'tracks.*.genre_id' => 'genre',
            'tracks.*.artist_id' => 'artist',
            'tracks.*.description' => 'description',
        ]);

        collect($tracks)->each(function ($form, $i) use ($release) {
            /** @var \App\Models\Track $track */
            $track = $release->tracks()->find($form['id']);

            if ($track) {
                // if ($form['image']) {
                $form['image'] = (new Media())->save('artwork', "tracks.*.image", $track->image, $i);
                // }

                $track->updateQuietly($form);
                $artists = [];
                if (isset($form['artist_id'])) {
                    $artists[$form['artist_id']] = ['type' => 'primary'];
                }
                $track->artists()->sync($artists);
            }
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $release_id)
    {
        /** @var \App\Models\Release $release */
        $release = $this->user->releases()
            ->whereId($release_id)
            ->orWhere('slug', $release_id)
            ->firstOrFail();

        $release->delete();

        return (new ReleaseCollection([]))->additional([
            'message' => "Release deleted successfully.",
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ]);
    }
}
