<?php

namespace App\Http\Controllers\Creator;

use App\Enums\Feature;
use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SoundController;
use App\Http\Resources\TrackCollection;
use App\Http\Resources\TrackResource;
use App\Models\Release;
use App\Models\Track;
use App\Services\Limits;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, string $release_id)
    {
        /** @var Release $release */
        $release = $this->user->releases()
            ->where(fn($q) => $q->where('slug', $release_id)->when(is_numeric($release_id), fn($q) => $q->orWhere('releases.id', $release_id)))
            ->firstOrFail();

        $tracks = $release
            ->tracks()
            ->with('release')
            ->paginate($request->perPage(15));

        return (new TrackCollection($tracks))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $release_id)
    {
        $allowedAudio = SoundController::audioRule();

        $this->validate($request, [
            'title' => ['required', 'string'],
            'image' => ['nullable', 'image', 'mimes:png,jpg'],
            'audio' => ['nullable', 'file', $allowedAudio],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'duration' => ['nullable', 'numeric'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'description' => ['nullable', 'string'],
            'stems_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        /** @var Release $release */
        $release = $this->user->releases()
            ->where(fn($q) => $q->where('slug', $release_id)->when(is_numeric($release_id), fn($q) => $q->orWhere('releases.id', $release_id)))
            ->firstOrFail();

        Limits::for($this->user)->ensureCanCharge(Feature::SELL_STEMS, $request->input('stems_price'), 0, 'stems_price');

        /** @var Track $track */
        $track = $release
            ->tracks()
            ->make();

        $track->slug = $request->input('slug');
        $track->title = $request->input('title');
        $track->genre_id = $request->input('genre_id');
        $track->privacy = $request->input('privacy', 'public');
        $track->duration = $request->input('duration', 0);
        $track->description = $request->input('description', '');
        $track->stems_price = $request->input('stems_price', $track->stems_price ?? 0) ?? 0;
        $track->save();

        $artists = [];
        if ($request->artist_id) {
            $artists[$request->artist_id] = ['type' => 'primary'];
        }
        $track
            ->artists()
            ->sync($artists);

        return (new TrackResource($track))
            ->additional([
                'message' => 'New track created successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $release_id, string $id)
    {
        $allowedAudio = SoundController::audioRule();

        $this->validate($request, [
            'title' => ['required', 'string'],
            'image' => ['nullable', 'image', 'mimes:png,jpg'],
            'audio' => ['nullable', 'file', $allowedAudio],
            'privacy' => ['nullable', 'string', 'in:public,private'],
            'duration' => ['nullable', 'numeric'],
            'genre_id' => ['required', 'string', 'exists:genres,id'],
            'artist_id' => ['required', 'string', 'exists:artists,id'],
            'description' => ['nullable', 'string'],
            'stems_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        /** @var Release $release */
        $release = $this->user->releases()
            ->where(fn($q) => $q->where('slug', $release_id)->when(is_numeric($release_id), fn($q) => $q->orWhere('releases.id', $release_id)))
            ->firstOrFail();

        /** @var Track $track */
        $track = $release->tracks()
            ->where(fn($q) => $q->where('slug', $id)->when(is_numeric($id), fn($q) => $q->orWhere('tracks.id', $id)))
            ->firstOrFail();

        Limits::for($this->user)->ensureCanCharge(Feature::SELL_STEMS, $request->input('stems_price'), (float) $track->stems_price, 'stems_price');

        $track->slug = $request->input('slug', $track->slug);
        $track->title = $request->input('title', $track->title);
        $track->genre_id = $request->input('genre_id', $track->genre_id);
        $track->privacy = $request->input('privacy', 'public');
        $track->duration = $request->input('duration', $track->duration);
        $track->description = $request->input('description', '');
        $track->stems_price = $request->input('stems_price', $track->stems_price ?? 0) ?? 0;
        $track->save();

        $artists = [];
        if ($request->artist_id) {
            $artists[$request->artist_id] = ['type' => 'primary'];
        }
        $track
            ->artists()
            ->sync($artists);

        return (new TrackResource($track))
            ->additional([
                'message' => 'Track updated successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * Create a new release or update an existing one, then save the tracks
     */
    public function save(Request $request)
    {
        $request->merge([
            'release.release_date' => $request->input('release.release_date', $request->release['releaseDate'] ?? now()),
        ]);

        $filled = $this->validate($request, [
            'release' => ['required', 'array'],
            'release_id' => ['nullable', 'string', 'exists:releases,id'],
            'release.title' => ['required', 'string'],
            'release.artwork' => ['nullable', 'image', 'mimes:png,jpg'],
            'release.privacy' => ['nullable', 'string', 'in:public,private'],
            'release.genre_id' => ['nullable', 'string', 'exists:genres,id'],
            'release.artist_id' => ['nullable', 'string', 'exists:artists,id'],
            'release.description' => ['nullable', 'string'],
            'release.isrc' => ['nullable', 'string'],
            'release.iswc' => ['nullable', 'string'],
            'release.record_label' => ['nullable', 'string'],
            'release.draft' => ['nullable', 'boolean'],
            'release.explicit' => ['nullable', 'boolean'],
            'release.composer' => ['nullable', 'string'],
            'release.publisher' => ['nullable', 'string'],
            'release.contains_music' => ['nullable', 'boolean'],
            'release.release_date' => ['nullable', 'string'],
            'release.price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ], [], [
            'release.title' => 'title',
            'release.artwork' => 'artwork',
            'release.genre_id' => 'genre',
            'release.artist_id' => 'artist',
            'release.description' => 'description',
            'release.isrc' => 'isrc',
            'release.record_label' => 'record label',
            'release.explicit' => 'explicit',
            'release.composer' => 'composer',
            'release.publisher' => 'publisher',
            'release.contains_music' => 'contains music',
            'release.release_date' => 'release date',
            'release.releaseDate' => 'release date',
        ]);

        $release = $this->user
            ->releases()
            ->find($request->release_id);
        $release ??= $this->user->releases()->create($filled['release']);

        return $this->store($request, $release->id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $release_id, string $id)
    {
        /** @var Release $release */
        $release = $this->user->releases()
            ->where(fn($q) => $q->where('slug', $release_id)->when(is_numeric($release_id), fn($q) => $q->orWhere('releases.id', $release_id)))
            ->firstOrFail();

        $release->tracks()
            ->where(fn($q) => $q->where('slug', $id)->when(is_numeric($id), fn($q) => $q->orWhere('tracks.id', $id)))
            ->delete();

        return (new TrackCollection([]))
            ->additional([
                'message' => 'Track deleted successfully.',
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
