<?php

namespace App\Http\Controllers\Creator;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtistResource;
use App\Models\Artist;
use Illuminate\Http\Request;

class ArtistController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => ['required', 'string', 'max:100'],
            'about' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'cover' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
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
     * Update the artist's profile details and photos. New photos are picked
     * up from the request when the artist is saved.
     */
    public function update(Request $request, Artist $artist)
    {
        $this->authorize('update', $artist);

        $valid = $this->validate($request, [
            'name' => ['required', 'string', 'max:100'],
            'about' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'cover' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'verified' => ['nullable', 'boolean'],
        ]);

        $artist->name = $valid['name'];
        $artist->about = $valid['about'] ?? null;

        if ($request->has('verified') && $this->user->can('verify', $artist)) {
            $artist->verified = $request->boolean('verified');
        }

        $artist->save();

        return (new ArtistResource($artist->refresh()))->additional([
            'message' => 'Artist profile updated.',
            'status' => 'success',
            'status_code' => HttpStatus::ACCEPTED,
        ])->response()->setStatusCode(HttpStatus::ACCEPTED->value);
    }
}
