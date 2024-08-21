<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtistResource;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => ['required', 'string'],
            'about' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:png,jpg'],
        ]);

        $artist = $this->user->artists()->make();
        $artist->name = $request->input('name');
        $artist->about = $request->input('about');
        $artist->save();

        return (new ArtistResource($artist))->additional([
            'message' => 'New artist created successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $artist = $this->user->artists()
            ->whereUsername($id)->when(is_numeric($id), fn ($q) => $q->orWhere('id', $id))
            ->firstOrFail();

        return (new ArtistResource($artist))->additional([
            'message' => 'Artist updated successfully.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}