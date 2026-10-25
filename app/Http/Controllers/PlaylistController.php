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

        // Use the listener's recommendations and history when they have some,
        // otherwise fall back to the most played published tracks.
        $personal = $user && Track::recommended($user)->exists();

        $query
            ->when($personal, fn (Builder $query) => $query->recommended($user))
            ->when(! $personal, fn (Builder $query) => $query
                ->whereHas('release', fn (Builder $q) => $q->live())
                ->withCount(['visits as plays' => fn (Builder $q) => $q->where('data->duration', '>', 0)])
                ->orderByDesc('plays'))
            // Paid tracks the listener hasn't bought can't play, so leave them out.
            ->playableBy($user)
            ->when($request->boolean('randomize'), fn (Builder $q) => $q->inRandomOrder());

        $tracks = $query->paginate($request->input('limit', 15));

        return (new TrackCollection($tracks))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }
}
