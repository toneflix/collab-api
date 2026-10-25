<?php

namespace App\Services\Explorers;

use App\Helpers\Providers;
use App\Http\Controllers\ProjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Projects
{
    public function __construct(
        public Request $request,
        public Collection $instance
    ) {}

    public function __destruct()
    {
        if ($this->request->has('new_projects')) {
            $resource = (new ProjectController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'active,latest,open',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('new_projects', 18),
            ]));

            $this->instance->put(
                'new_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('top_projects')) {
            $resource = (new ProjectController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'active,top,open',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('top_projects', 18),
            ]));

            $this->instance->put(
                'top_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($this->request->has('active_projects')) {
            $resource = (new ProjectController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'active,latest,open',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('active_projects', 18),
            ]));

            $this->instance->put(
                'active_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }
        if ($this->request->has('random_projects')) {
            $resource = (new ProjectController)->index(Request::createFrom($this->request)->merge([
                'filters' => 'active,random,open',
                'period' => $this->request->input('period', 'month'),
                'limit' => $this->request->input('random_projects', 18),
            ]));

            $this->instance->put(
                'random_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }
    }
}
