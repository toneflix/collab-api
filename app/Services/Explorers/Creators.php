<?php

namespace App\Services\Explorers;

use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Creators
{
    public function __construct(
        public Request $request,
        public Collection $instance
    ) {}

    public function __destruct()
    {
        if ($this->request->has('top_creators')) {
            $resource = (new UserController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'creator,top',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('top_creators', 18),
            ]));

            $this->instance->put(
                'top_creators',
                Section::from($resource)
            );
        }

        if ($this->request->has('new_creators')) {
            $resource = (new UserController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'creator,latest',
                'period' => $this->request->input('period', 'week'),
                'limit' => $this->request->input('new_creators', 18),
            ]));

            $this->instance->put(
                'new_creators',
                Section::from($resource)
            );
        }

        if ($this->request->has('random_creators')) {
            $resource = (new UserController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'creator,random',
                'limit' => $this->request->input('random_creators', 18),
            ]));

            $this->instance->put(
                'random_creators',
                Section::from($resource)
            );
        }
    }
}
