<?php

namespace App\Services\Explorers;

use App\Helpers\Providers;
use App\Http\Controllers\ArtistController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Artists
{
    public function __construct(
        public Request $request,
        public Collection $instance
    ) {}

    public function __destruct()
    {
        if ($this->request->has('latest_artists')) {
            $resource = (new ArtistController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'latest',
                'limit' => $this->request->input('latest_artists', 18),
            ]));

            $this->instance->put(
                'latest_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('trending_artists')) {
            $resource = (new ArtistController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'trending',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('trending_artists', 18),
            ]));

            $this->instance->put(
                'trending_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('recently_played_artists')) {
            $resource = (new ArtistController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'recently_played',
                'period' => $this->request->input('period', 'week'),
                'limit' => $this->request->input('recently_played_artists', 18),
            ]));

            $this->instance->put(
                'recently_played_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('random_artists')) {
            $resource = (new ArtistController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'random',
                'limit' => $this->request->input('random_artists', 18),
            ]));

            $this->instance->put(
                'random_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('recommended_artists')) {
            $resource = (new ArtistController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'recommends',
                'limit' => $this->request->input('recommended_artists', 18),
            ]));

            $this->instance->put(
                'recommended_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }
    }
}
