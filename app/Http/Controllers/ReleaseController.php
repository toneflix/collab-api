<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ReleaseCollection;
use App\Http\Resources\ReleaseResource;
use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReleaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Release::query();

        $filters = str($request->filters)->remove(' ')->explode(',');

        $base = fn ($query) => $query
            ->when(
                $filters->contains('recently_played'),
                fn (Builder $q) => $q->recentlyPlayed($request->user('sanctum'), $request->period ?? 'week')
            )
            ->when(
                $filters->contains('liked') && $request->user('sanctum'),
                fn (Builder $q) => $q->isLiked($request->user('sanctum'))
            )
            ->when(
                $filters->contains('own_collabs') && $request->user('sanctum'),
                fn (Builder $q) => $q->userCollabs($request->user('sanctum'), $request->period)
            )
            ->when($request->has('genre'), fn (Builder $q) => $q->genre($request->genre))
            ->when($request->has('artist_id'), fn (Builder $q) => $q->forArtist($request->artist_id))
            ->when($filters->contains('latest'), fn (Builder $q) => $q->latestInPeriod('weeks_2'))
            ->when($filters->contains('random'), fn (Builder $q) => $q->inRandomOrder())
            ->when($filters->contains('top'), fn (Builder $q) => $q->popular('top', $request->period));

        $query->where($base)
            ->when($filters->contains('album'), function (Builder $q) {
                // TODO: Make this to check if the realease has more than 1 track
                $q->whereHas('tracks', null, '>=', 1);
            });

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
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Release $release)
    {
        $release->stream(0, $request->user('sanctum'));

        return (new ReleaseResource($release))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}