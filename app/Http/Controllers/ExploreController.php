<?php

namespace App\Http\Controllers;

use App\Helpers\Providers;
use Illuminate\Http\Request;

class ExploreController extends Controller
{
    public function index(Request $request)
    {
        $data = collect([]);
        // dd($request->user('sanctum'));

        if ($request->has('recently_played')) {
            $resource = (new TrackController())->index(Request::createFrom($request)->merge([
                'filters' => 'recently_played',
                'limit' => $request->input('recently_played', 15),
            ]));

            $data->put(
                'recently_played',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('trending')) {
            $resource = (new TrackController())->index(Request::createFrom($request)->merge([
                'filters' => 'trending',
                'period' => $request->input('period', 'week'),
                'limit' => $request->input('trending', 18),
            ]));

            $data->put(
                'trending',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('latest_tracks')) {
            $resource = (new TrackController())->index(Request::createFrom($request)->merge([
                'filters' => 'latest',
                'period' => $request->input('period', 'week'),
                'limit' => $request->input('latest_tracks', 18),
            ]));

            $data->put(
                'latest_tracks',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('top_creators')) {
            $resource = (new UserController())->index(Request::createFrom($request)->merge([
                'filters' => 'creator,top',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('top_creators', 18),
            ]));

            $data->put(
                'top_creators',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('new_creators')) {
            $resource = (new UserController())->index(Request::createFrom($request)->merge([
                'filters' => 'creator,latest',
                'period' => $request->input('period', 'week'),
                'limit' => $request->input('new_creators', 18),
            ]));

            $data->put(
                'new_creators',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('random_creators')) {
            $resource = (new UserController())->index(Request::createFrom($request)->merge([
                'filters' => 'creator,random',
                'limit' => $request->input('random_creators', 18),
            ]));

            $data->put(
                'random_creators',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('new_projects')) {
            $resource = (new ProjectController())->index(Request::createFrom($request)->merge([
                'filters' => 'active,latest',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('new_projects', 18),
            ]));

            $data->put(
                'new_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('top_projects')) {
            $resource = (new ProjectController())->index(Request::createFrom($request)->merge([
                'filters' => 'active,top',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('top_projects', 18),
            ]));

            $data->put(
                'top_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('active_projects')) {
            $resource = (new ProjectController())->index(Request::createFrom($request)->merge([
                'filters' => 'active,latest',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('active_projects', 18),
            ]));

            $data->put(
                'active_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }
        if ($request->has('random_projects')) {
            $resource = (new ProjectController())->index(Request::createFrom($request)->merge([
                'filters' => 'active,random',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('random_projects', 18),
            ]));

            $data->put(
                'random_projects',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('top_albums')) {
            $resource = (new ReleaseController())->index(Request::createFrom($request)->merge([
                'filters' => 'album,top',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('top_albums', 18),
            ]));

            $data->put(
                'top_albums',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('latest_albums')) {
            $resource = (new ReleaseController())->index(Request::createFrom($request)->merge([
                'filters' => 'album,latest',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('latest_albums', 18),
            ]));

            $data->put(
                'latest_albums',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('random_albums')) {
            $resource = (new ReleaseController())->index(Request::createFrom($request)->merge([
                'filters' => 'album,random',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('random_albums', 18),
            ]));

            $data->put(
                'random_albums',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('latest_artists')) {
            $resource = (new ArtistController())->index(Request::createFrom($request)->merge([
                'filters' => 'latest',
                'limit' => $request->input('latest_artists', 18),
            ]));

            $data->put(
                'latest_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('trending_artists')) {
            $resource = (new ArtistController())->index(Request::createFrom($request)->merge([
                'filters' => 'trending',
                'period' => $request->input('period', 'month'),
                'limit' => $request->input('trending_artists', 18),
            ]));

            $data->put(
                'trending_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('random_artists')) {
            $resource = (new ArtistController())->index(Request::createFrom($request)->merge([
                'filters' => 'random',
                'limit' => $request->input('random_artists', 18),
            ]));

            $data->put(
                'random_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        if ($request->has('recommended_artists')) {
            $resource = (new ArtistController())->index(Request::createFrom($request)->merge([
                'filters' => 'recommends',
                'limit' => $request->input('recommended_artists', 18),
            ]));

            $data->put(
                'recommended_artists',
                Providers::paginator($resource->resource, ['data' => 'list'], ['links', 'meta' => ['links', 'path']])
            );
        }

        // $data->dd();
        return Providers::response()->success([
            'data' => $data,
        ]);
    }
}
