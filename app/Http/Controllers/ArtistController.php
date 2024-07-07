<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Http\Resources\ArtistCollection;
use App\Http\Resources\ArtistResource;
use App\Models\Artist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Artist::query();

        $filters = str($request->filters)->remove(' ')->explode(',');

        $query
            ->when($request->has('search'), function (Builder $query) use ($request) {
                if (str($request->search)->contains('@')) {
                    $query->where('username', str($request->search)->remove('@')->toString());
                } elseif (str($request->search)->contains(['>', '<']) && str($request->search)->numbers()->toInteger() > 0) {
                    $query->where(
                        'reputation',
                        substr($request->search, 0, 1),
                        str($request->search)->numbers()
                    );
                } else {
                    $query->where(function ($query) use ($request) {
                        $query->where('name', 'like', "%$request->search%")
                            // ->orWhere('country', $request->search)
                            // ->orWhere('city', $request->search)
                            ->orWhereFullText('about', $request->search);
                    });
                }
            })
            ->when($request->has('user_id'), fn (Builder $q) => $q->whereUserId($request->user_id))
            ->when($filters->contains('trending'), fn (Builder $q) => $q->trending($request->period));

        $artists = $query->paginate($request->input('limit', 15));

        return (new ArtistCollection($artists))->additional([
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Artist $artist)
    {
        return (new ArtistResource($artist))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Artist $artist)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Artist $artist)
    {
        //
    }
}