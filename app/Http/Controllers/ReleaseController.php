<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ReleaseCollection;
use App\Http\Resources\ReleaseResource;
use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReleaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Release::query();

        $filters = str($request->filters)->remove(' ')->explode(',');

        $query
            ->when($filters->contains('album'), function (Builder $q) {
                $q->having('tracks_count', '>', 1);
            })
            ->when($request->has('genre'), fn (Builder $q) => $q->genre($request->genre))
            ->when($request->has('artist_id'), fn (Builder $q) => $q->forArtist($request->artist_id))
            ->when($filters->contains('latest'), fn (Builder $q) => $q->latestInPeriod('weeks_2'))
            ->when($filters->contains('random'), fn (Builder $q) => $q->inRandomOrder())
            ->when($filters->contains('top'), fn (Builder $q) => $q->popular('top', $request->period));

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
    public function show(Release $release)
    {
        $release->stream(0, request()->user('sanctum'));

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