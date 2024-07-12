<?php

namespace App\Services\Explorers;

use App\Helpers\Providers;
use App\Http\Controllers\TrackController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Tracks
{
    public function __construct(
        public Request $request,
        public Collection $instance
    ) {
    }

    public function __destruct()
    {
        if ($this->request->has('recently_played')) {
            $resource = (new TrackController())->index(Request::createFrom($this->request)->merge([
                'filters' => 'recently_played',
                'limit' => $this->request->input('recently_played', 15),
            ]));

            $this->instance->put(
                'recently_played',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('trending')) {
            $resource = (new TrackController())->index(Request::createFrom($this->request)->merge([
                'filters' => 'trending',
                'period' => $this->request->input('period', 'week'),
                'limit' => $this->request->input('trending', 18),
            ]));

            $this->instance->put(
                'trending',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('latest_tracks')) {
            $resource = (new TrackController())->index(Request::createFrom($this->request)->merge([
                'filters' => 'latest',
                'period' => $this->request->input('period', 'week'),
                'limit' => $this->request->input('latest_tracks', 18),
            ]));

            $this->instance->put(
                'latest_tracks',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('liked_tracks')) {
            $resource = (new TrackController())->index(Request::createFrom($this->request)->merge([
                'filters' => 'liked',
                'period' => $this->request->input('period'),
                'limit' => $this->request->input('liked_tracks', 18),
            ]));

            $this->instance->put(
                'liked_tracks',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('own_collab_tracks')) {
            $resource = (new TrackController())->index(Request::createFrom($this->request)->merge([
                'filters' => 'own_collabs',
                'period' => $this->request->input('period'),
                'limit' => $this->request->input('own_collab_tracks', 18),
            ]));

            $this->instance->put(
                'own_collab_tracks',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }
    }
}
