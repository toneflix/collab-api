<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ReleaseCollection;
use App\Http\Resources\ReleaseResource;
use App\Models\Release;
use Illuminate\Http\Request;

class ReleaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Release::query()->make();

        $releases = $query->paginate($request->input('limit', 15));

        return (new ReleaseCollection($releases))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
    }

    /**
     * Display the specified resource.
     */
    public function show(Release $release)
    {
        return (new ReleaseResource($release))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
