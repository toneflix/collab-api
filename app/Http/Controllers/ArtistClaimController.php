<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\ArtistClaimResource;
use App\Models\Artist;
use App\Models\ArtistClaim;
use App\Services\Artists\ArtistClaims;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * People asking to manage an artist no account manages yet.
 */
class ArtistClaimController extends Controller
{
    public function store(Request $request, Artist $artist, ArtistClaims $claims)
    {
        $this->authorize('claim', $artist);

        $valid = $request->validate([
            'role' => ['required', Rule::in(ArtistClaim::ROLES)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'links' => ['required', 'array', 'min:1', 'max:5'],
            'links.*' => ['required', 'url:http,https', 'max:255'],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'links.required' => 'Add at least one link that shows your connection to the artist.',
            'links.*.url' => 'Enter a full link, starting with https://.',
        ]);

        $claim = $claims->submit(
            $request->user(),
            $artist,
            collect($valid)->except('document')->all(),
            $request->file('document'),
        );

        return Providers::response()->success([
            'data' => (new ArtistClaimResource($claim))->resolve($request),
            'message' => 'Thanks. We\'ll review your claim and let you know.',
        ], HttpStatus::CREATED);
    }
}
