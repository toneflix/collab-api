<?php

namespace App\Services\Explorers;

use App\Http\Controllers\ReleaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Albums
{
    public function __construct(
        public Request $request,
        public Collection $instance
    ) {}

    public function __destruct()
    {
        if ($this->request->has('top_albums')) {
            $resource = (new ReleaseController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'album,top',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('top_albums', 18),
            ]));

            $this->instance->put(
                'top_albums',
                Section::from($resource)
            );
        }

        if ($this->request->has('latest_albums')) {
            $resource = (new ReleaseController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'album,latest',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('latest_albums', 18),
            ]));

            $this->instance->put(
                'latest_albums',
                Section::from($resource)
            );
        }

        if ($this->request->has('recently_played_albums')) {
            $resource = (new ReleaseController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'recently_played',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('recently_played_albums', 18),
            ]));

            $this->instance->put(
                'recently_played_albums',
                Section::from($resource)
            );
        }

        if ($this->request->has('random_albums')) {
            $resource = (new ReleaseController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'album,random',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('random_albums', 18),
            ]));

            $this->instance->put(
                'random_albums',
                Section::from($resource)
            );
        }

        if ($this->request->has('liked_albums')) {
            $resource = (new ReleaseController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'liked',
                'period' => $this->request->input('period'),
                'limit' => $this->request->input('liked_albums', 18),
            ]));

            $this->instance->put(
                'liked_albums',
                Section::from($resource)
            );
        }
        if ($this->request->has('own_collab_albums')) {
            $resource = (new ReleaseController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'own_collabs',
                'period' => $this->request->input('period'),
                'limit' => $this->request->input('own_collab_albums', 18),
            ]));

            $this->instance->put(
                'own_collab_albums',
                Section::from($resource)
            );
        }
    }
}
