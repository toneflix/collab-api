<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SongResource;
use App\Models\Song;
use App\Services\Composer\Songs;

/**
 * Forks a song into the user's Composer.
 */
class SongForkController extends Controller
{
    public function store(Songs $songs, Song $song)
    {
        $this->authorize('fork', $song);

        $fork = $songs->fork($song, $this->user);
        $fork->load(['beat', 'forkedFrom']);

        return (new SongResource($fork))
            ->additional([
                'message' => 'Song forked.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }
}
