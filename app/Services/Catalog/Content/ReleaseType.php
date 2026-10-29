<?php

namespace App\Services\Catalog\Content;

use App\Models\Release;
use App\Models\User;
use App\Services\Moderation\Reportables;
use App\Services\Releases\ReleaseVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Albums and singles. Publishing one from here puts it live without review.
 */
class ReleaseType extends ContentType
{
    public function __construct(Reportables $reportables, protected ReleaseVersions $versions)
    {
        parent::__construct($reportables);
    }

    public function name(): string
    {
        return 'release';
    }

    public function model(): string
    {
        return Release::class;
    }

    public function statuses(): array
    {
        return ['live', 'draft', 'review', 'retired', 'taken_down'];
    }

    public function rules(?Model $item = null): array
    {
        return [
            'user_id' => [$item ? 'sometimes' : 'required', 'integer', 'exists:users,id'],
            'artist_id' => ['required', 'integer', 'exists:artists,id'],
            'genre_id' => ['required', 'integer', 'exists:genres,id'],
            'title' => ['required', 'string', 'max:150'],
            'version' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:5000'],
            'record_label' => ['nullable', 'string', 'max:150'],
            'release_date' => ['nullable', 'date'],
            'privacy' => ['nullable', Rule::in(['public', 'private'])],
            'explicit' => ['nullable', 'boolean'],
            'published' => ['nullable', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'downloadable' => ['nullable', 'boolean'],
            'artwork' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ];
    }

    public function create(array $data, User $admin): Model
    {
        $release = new Release;
        $release->user_id = $data['user_id'];
        $release->draft = true;

        return $this->update($release, $data, $admin);
    }

    /**
     * New artwork in the request's `artwork` field is stored when the release
     * saves.
     */
    public function update(Model $item, array $data, User $admin): Model
    {
        /** @var Release $item */
        $wasDraft = ! $item->exists || $item->draft;
        $publish = (bool) ($data['published'] ?? ! $item->draft);

        $item->user_id = $data['user_id'] ?? $item->user_id;
        $item->artist_id = $data['artist_id'];
        $item->genre_id = $data['genre_id'];
        $item->title = $data['title'];
        $item->version = $data['version'] ?? null;
        $item->description = $data['description'] ?? '';
        $item->record_label = $data['record_label'] ?? '';
        $item->release_date = $data['release_date'] ?? $item->release_date ?? now();
        $item->privacy = $data['privacy'] ?? $item->privacy ?? 'public';
        $item->explicit = (bool) ($data['explicit'] ?? $item->explicit);
        $item->price = $data['price'] ?? $item->price ?? 0;
        $item->downloadable = (bool) ($data['downloadable'] ?? $item->downloadable);
        $item->draft = ! $publish;
        $item->save();

        if ($wasDraft && $publish) {
            $this->versions->published($item);
        }

        if ($item->isInReview()) {
            $this->versions->approve($item, $admin);
        }

        return $item;
    }

    public function fields(Model $item): array
    {
        /** @var Release $item */
        return [
            'userId' => $item->user_id,
            'artistId' => $item->artist_id,
            'artistName' => $item->artist?->name,
            'genreId' => $item->genre_id,
            'title' => $item->title,
            'version' => $item->version,
            'description' => $item->description,
            'recordLabel' => $item->record_label,
            'releaseDate' => $item->release_date?->format('Y-m-d'),
            'privacy' => $item->privacy,
            'explicit' => (bool) $item->explicit,
            'published' => ! $item->draft,
            'inReview' => $item->isInReview(),
            'retired' => $item->isRetired(),
            'price' => (float) $item->price,
            'downloadable' => (bool) $item->downloadable,
            'imageUrl' => $item->files['artwork'] ?? null,
        ];
    }

    protected function with(): array
    {
        return ['artist' => fn($q) => $q->withoutGlobalScopes()];
    }

    protected function whereStatus(Builder $query, string $status): void
    {
        match ($status) {
            'live' => $query->live(),
            'draft' => $query->where('draft', true),
            'review' => $query->inReview(),
            'retired' => $query->whereNotNull('retired_at'),
            'taken_down' => $query->whereNotNull('taken_down_at'),
        };
    }
}
