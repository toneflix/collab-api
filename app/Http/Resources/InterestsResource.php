<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A user's account type, identities and the genres and topics they picked.
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

        $topics = $this->topics()
            ->orderBy('topics.title')
            ->get(['topics.id', 'topics.slug', 'topics.title']);

        return [
            'accountType' => $this->account_type?->value,
            'identities' => $this->identities ?? [],
            'genres' => $genres
                ->map(fn($genre) => ['id' => $genre->id, 'slug' => $genre->slug, 'title' => $genre->title])
                ->values()
                ->all(),
            'topics' => $topics
                ->map(fn($topic) => ['id' => $topic->id, 'slug' => $topic->slug, 'title' => $topic->title])
                ->values()
                ->all(),
            'interestsAt' => $this->interests_at,
        ];
    }
}
