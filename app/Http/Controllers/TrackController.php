<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\TrackCollection;
use App\Http\Resources\TrackResource;
use App\Models\Release;
use App\Models\Track;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Release $release)
    {
        $query = $release->tracks()->getQuery();

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
    public function show(Release $release, Track $track)
    {
        return (new TrackResource($track))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Track $track)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Track $track)
    {
        //
    }
}
