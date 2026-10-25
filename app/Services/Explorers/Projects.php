<?php

namespace App\Services\Explorers;

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
                Section::from($resource)
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
                Section::from($resource)
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
                Section::from($resource)
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
                Section::from($resource)
            );
        }
    }
}
