<?php

namespace App\Services\Releases;

use App\Models\Artist;
use App\Models\ProjectCollaborator;
use App\Models\Track;
use Illuminate\Support\Collection;

/**
 * Who made a released track: its artists by part, the studio project's
 * collaborators and the release's rights details.
 */
class TrackCredits
{
    /** Most artists or collaborators listed */
    public const LIMIT = 45;

    /** Artist parts in the order they are listed */
    public const PARTS = [
        'primary' => 'Artist',
        'feature' => 'Featured artist',
        'producer' => 'Producer',
        'writer' => 'Writer',
    ];

    public function __construct(
        protected Track $track,
    ) {}

    /**
     * @return list<array{id: int, name: string, username: string|null, imageUrl: string|null, role: string}>
     */
    public function artists(): array
    {
        $order = array_keys(self::PARTS);

        return $this->track
            ->artists()
            ->withPivot('type')
            ->limit(self::LIMIT)
            ->get()
            ->sortBy(fn(Artist $artist) => array_search($artist->track->type ?? 'primary', $order, true))
            ->map(fn(Artist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
                'username' => $artist->username,
                'imageUrl' => $artist->images['image'] ?? null,
                'role' => self::PARTS[$artist->track->type ?? 'primary'] ?? str($artist->track->type)->headline()->toString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Accepted collaborators of the project the track was made in.
     *
     * @return list<array{id: int, name: string, username: string|null, imageUrl: string|null, role: string}>
     */
    public function collaborators(): array
    {
        $project = $this->track->projectTrack?->project;

        if (! $project) {
            return [];
        }

        $collaborators = $project
            ->collaborators()
            ->accepted()
            ->with('user')
            ->oldest('id')
            ->limit(self::LIMIT)
            ->get();

        return $collaborators
            ->filter(fn(ProjectCollaborator $collaborator) => $collaborator->user !== null)
            ->map(fn(ProjectCollaborator $collaborator) => [
                'id' => $collaborator->user->id,
                'name' => $collaborator->user->fullname,
                'username' => $collaborator->user->username,
                'imageUrl' => $collaborator->user->images['image'] ?? null,
                'role' => str($collaborator->role ?: 'collaborator')->headline()->toString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Rights details from the release, leaving out the ones not filled in.
     *
     * @return array<string, string>
     */
    public function details(): array
    {
        $release = $this->track->release;

        return Collection::make([
            'composer' => $release?->composer,
            'publisher' => $release?->publisher,
            'recordLabel' => $release?->record_label,
            'isrc' => $release?->isrc,
            'releaseDate' => $release?->release_date?->toDateString(),
            'genre' => $this->track->genre?->title,
        ])
            ->filter()
            ->map(fn($value) => (string) $value)
            ->all();
    }
}
