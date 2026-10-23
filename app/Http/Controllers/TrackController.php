<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\TrackCollection;
use App\Http\Resources\TrackResource;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, ?Release $release = null)
    {
        $query = $release
            ? $release->tracks()->getQuery()
            : Track::query();

        $filters = str($request->filters)->remove(' ')->explode(',');

        $query
            ->when($request->has('genre'), fn (Builder $q) => $q->genre($request->genre))
            ->when($filters->contains('latest'), fn (Builder $q) => $q->latestInPeriod($request->period))
            ->when(
                $filters->contains('liked') && $request->user('sanctum'),
                fn (Builder $q) => $q->likedBy($request->user('sanctum'))
            )
            ->when(
                $filters->contains('own_collabs') && $request->user('sanctum'),
                fn (Builder $q) => $q->userCollabs($request->user('sanctum'), $request->period)
            )
            ->when(
                $filters->contains('recently_played') && $request->user('sanctum'),
                fn (Builder $q) => $q->recentlyPlayed($request->user('sanctum'), 3)
            )->when(
                $filters->contains('trending'),
                fn (Builder $q) => $q->popular('top', $request->period)
            )->when(
                $request->has('artist_id') && !$filters->contains('features'),
                fn (Builder $q) => $q->forArtist($request->artist_id)
            )->when(
                $filters->contains('features') && $request->has('artist_id'),
                fn (Builder $q) => $q->forFeaturedArtist($request->artist_id)
            );

        $tracks = $query->paginate($request->input('limit', 15));

        return (new TrackCollection($tracks))->additional([
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Release $release, Track $track)
    {
        $track->stream(0, $request->user('sanctum'));

        return (new TrackResource($track))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Release $release, Track $track)
    {
        $this->validate($request, [
            'duration' => 'nullable|numeric|min:0'
        ]);

        $track->stream($request->integer('duration'), $request->user('sanctum'));

        return (new TrackResource($track))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Track $track)
    {
        //
    }
}
