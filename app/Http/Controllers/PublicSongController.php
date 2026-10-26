<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\PublicSongCollection;
use App\Http\Resources\PublicSongResource;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Published Composer songs: the public list and each song's page.
 */
class PublicSongController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:latest,forks'],
            'user' => ['nullable', 'string', 'max:100'],
        ]);

        $writer = $data['user'] ?? null;

        $songs = Song::query()
            ->published()
            ->with('user')
            ->withCount('forks')
            ->search($data['search'] ?? null)
            ->when(
                $writer,
                fn(Builder $q) => $q->whereHas(
                    'user',
                    fn(Builder $u) => is_numeric($writer) ? $u->whereKey($writer) : $u->where('username', $writer),
                ),
            )
            ->when(
                ($data['sort'] ?? 'latest') === 'forks',
                fn(Builder $q) => $q->orderByDesc('forks_count'),
            )
            ->latest('published_at')
            ->latest('id')
            ->paginate($request->perPage(20, 50));

        return (new PublicSongCollection($songs))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function show(Song $song)
    {
        Gate::forUser($this->user)->authorize('read', $song);

        $song
            ->load(['user', 'forkedFrom.user', 'track.project'])
            ->loadCount('forks');

        return (new PublicSongResource($song))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
