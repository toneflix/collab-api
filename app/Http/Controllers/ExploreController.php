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
                'limit' => $request->input('recently_played', 15)
            ]));

            $data->put('recently_played', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('trending')) {
            $resource = (new TrackController())->index(Request::createFrom($request)->merge([
                'filters' => 'trending',
                'period' => 'week',
                'limit' => $request->input('trending', 18)
            ]));

            $data->put('trending', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('top_creators')) {
            $resource = (new UserController())->index(Request::createFrom($request)->merge([
                'filters' => 'creator,top',
                'period' => 'month',
                'limit' => $request->input('top_creators', 18)
            ]));

            $data->put('top_creators', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('new_creators')) {
            $resource = (new UserController())->index(Request::createFrom($request)->merge([
                'filters' => 'creator,latest',
                'period' => 'week',
                'limit' => $request->input('new_creators', 18)
            ]));

            $data->put('new_creators', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('top_projects')) {
            $resource = (new ProjectController())->index(Request::createFrom($request)->merge([
                'filters' => 'active,top',
                'period' => 'month',
                'limit' => $request->input('top_projects', 18)
            ]));

            $data->put('top_projects', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('active_projects')) {
            $resource = (new ProjectController())->index(Request::createFrom($request)->merge([
                'filters' => 'active,latest',
                'period' => 'month',
                'limit' => $request->input('active_projects', 18)
            ]));

            $data->put('active_projects', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('top_albums')) {
            $resource = (new ReleaseController())->index(Request::createFrom($request)->merge([
                'filters' => 'album,top',
                'period' => 'month',
                'limit' => $request->input('top_albums', 18)
            ]));

            $data->put('top_albums', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('latest_albums')) {
            $resource = (new ReleaseController())->index(Request::createFrom($request)->merge([
                'filters' => 'album,latest',
                'period' => 'month',
                'limit' => $request->input('latest_albums', 18)
            ]));

            $data->put('latest_albums', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        if ($request->has('trending_artists')) {
            $resource = (new ArtistController())->index(Request::createFrom($request)->merge([
                'filters' => 'trending',
                'period' => 'month',
                'limit' => $request->input('trending_artists', 18)
            ]));

            $data->put('trending_artists', ['list' => $resource, 'lastPage' => $resource->resource->lastPage()]);
        }

        // $data->dd();
        return Providers::response()->success([
            'data' => $data
        ]);
    }
}