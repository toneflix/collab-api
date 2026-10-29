<?php

namespace App\Services\Catalog\Content;

use App\Enums\TrackOrigin;
use App\Http\Controllers\SoundController;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Moderation\Reportables;
use App\Services\Releases\TrackOrigins;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Released tracks. Each belongs to a release, whose owner owns the track.
 */
class TrackType extends ContentType
{
    public function __construct(Reportables $reportables, protected TrackOrigins $origins)
    {
        parent::__construct($reportables);
    }

    public function name(): string
    {
        return 'track';
    }

    public function model(): string
    {
        return Track::class;
    }

    public function statuses(): array
    {
        return ['public', 'private', 'no_audio', 'taken_down', 'unranked', ...TrackOrigin::values()];
    }

    public function rules(?Model $item = null): array
    {
        return [
            'release_id' => [$item ? 'sometimes' : 'required', 'integer', 'exists:releases,id'],
            'artist_id' => ['nullable', 'integer', 'exists:artists,id'],
            'genre_id' => ['required', 'integer', 'exists:genres,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['nullable', Rule::in(['public', 'private'])],
            'stems_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'audio' => [$item ? 'nullable' : 'required', 'file', SoundController::audioRule()],
            'origin' => ['nullable', Rule::in(TrackOrigin::values())],
        ];
    }

    public function create(array $data, User $admin): Model
    {
        return $this->update(new Track, $data, $admin);
    }

    /**
     * New artwork and audio in the request's `image` and `audio` fields are
     * stored when the track saves, which also queues its sound checks.
     */
    public function update(Model $item, array $data, User $admin): Model
    {
        /** @var Track $item */
        $item->release_id = $data['release_id'] ?? $item->release_id;
        $item->genre_id = $data['genre_id'];
        $item->title = $data['title'];
        $item->description = $data['description'] ?? '';
        $item->privacy = $data['privacy'] ?? $item->privacy ?? 'public';
        $item->stems_price = $data['stems_price'] ?? $item->stems_price ?? 0;
        $item->save();

        $this->rank($item, $data);

        $artistId = $data['artist_id'] ?? Release::query()
            ->withoutGlobalScopes()
            ->whereKey($item->release_id)
            ->value('artist_id');

        $item
            ->artists()
            ->sync($artistId ? [$artistId => ['type' => 'primary']] : []);

        return $item;
    }

    /**
     * An admin's rank stands until they set it back to automatic (empty).
     *
     * @param  array<string, mixed>  $data
     */
    protected function rank(Track $track, array $data): void
    {
        if (! array_key_exists('origin', $data)) {
            return;
        }

        $origin = TrackOrigin::tryFrom((string) $data['origin']);
        $backToAutomatic = ! $origin && $track->origin_by === TrackOrigins::ADMIN;

        if ($origin !== $track->origin || $backToAutomatic) {
            $this->origins->setByAdmin($track, $origin);
        }
    }

    public function bulkActions(): array
    {
        return [
            'make_public' => 'Make public',
            'make_private' => 'Make private',
        ];
    }

    public function applyBulk(Model $item, string $action, User $admin): bool
    {
        /** @var Track $item */
        $privacy = $action === 'make_public' ? 'public' : 'private';

        if ($item->privacy === $privacy) {
            return false;
        }

        $item->privacy = $privacy;

        return $item->save();
    }

    public function fields(Model $item): array
    {
        /** @var Track $item */
        $artist = $item->artist->first();

        return [
            'releaseId' => $item->release_id,
            'releaseTitle' => $item->release?->title,
            'artistId' => $artist?->id,
            'artistName' => $artist?->name,
            'genreId' => $item->genre_id,
            'title' => $item->title,
            'description' => $item->description,
            'privacy' => $item->privacy,
            'stemsPrice' => (float) $item->stems_price,
            'duration' => (float) $item->duration,
            'hasAudio' => (bool) ($item->audio || $item->project_track_id),
            'imageUrl' => $item->files['image'] ?? null,
            'origin' => $item->origin?->value,
            'originBy' => $item->origin_by,
            'originPending' => ! $item->origin && ! $item->project_track_id,
        ];
    }

    protected function with(): array
    {
        return [
            'release' => fn($q) => $q->withoutGlobalScopes(),
            'artist' => fn($q) => $q->withoutGlobalScopes(),
        ];
    }

    protected function whereStatus(Builder $query, string $status): void
    {
        match ($status) {
            'public' => $query->where('privacy', 'public'),
            'private' => $query->where('privacy', 'private'),
            'no_audio' => $query
                ->whereNull('audio')
                ->whereNull('project_track_id'),
            'taken_down' => $query->whereNotNull('taken_down_at'),
            'unranked' => $query->whereNull('origin'),
            default => $query->where('origin', $status),
        };
    }
}
