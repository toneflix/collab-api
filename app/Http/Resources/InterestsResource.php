<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A user's account type, identities and the genres they picked as interests.
 *
 * @mixin User
 */
class InterestsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $genres = $this->interests()
            ->orderBy('genres.title')
            ->get(['genres.id', 'genres.slug', 'genres.title']);

        return [
            'accountType' => $this->account_type?->value,
            'identities' => $this->identities ?? [],
            'genres' => $genres
                ->map(fn($genre) => ['id' => $genre->id, 'slug' => $genre->slug, 'title' => $genre->title])
                ->values()
                ->all(),
            'interestsAt' => $this->interests_at,
        ];
    }
}
