<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ExploreResource;
use App\Services\Explorers\Albums;
use App\Services\Explorers\Artists;
use App\Services\Explorers\Creators;
use App\Services\Explorers\Projects;
use App\Services\Explorers\Tracks;
use Illuminate\Http\Request;

class ExploreController extends Controller
{
    public function index(Request $request)
    {
        $data = collect([]);

        new Tracks($request, $data);
        new Creators($request, $data);
        new Projects($request, $data);
        new Albums($request, $data);
        new Artists($request, $data);

        return (new ExploreResource($data))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }
}
