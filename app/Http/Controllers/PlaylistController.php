<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\TrackCollection;
use App\Models\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Track::query();

        $user = $request->user('sanctum');

        $query
            ->when($user, function (Builder $query) use ($user) {
                $query->recommended($user);
            })
            ->when($request->boolean('randomize'), fn (Builder $q) => $q->inRandomOrder());

        $tracks = $query->paginate($request->input('limit', 15));

        return (new TrackCollection($tracks))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}