<?php

namespace App\Services\Catalog\Content;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Artist profiles. An artist with no owner can be claimed.
 */
class ArtistType extends ContentType
{
    public function name(): string
    {
        return 'artist';
    }

    public function model(): string
    {
        return Artist::class;
    }

    public function statuses(): array
    {
        return ['verified', 'unverified', 'unclaimed'];
    }

    public function rules(?Model $item = null): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:100'],
            'about' => ['nullable', 'string', 'max:2000'],
            'verified' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'cover' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ];
    }

    public function create(array $data, User $admin): Model
    {
        return $this->update(new Artist, $data, $admin);
    }

    /**
     * New photos in the request's `image` and `cover` fields are stored when
     * the artist saves.
     */
    public function update(Model $item, array $data, User $admin): Model
    {
        /** @var Artist $item */
        $item->user_id = array_key_exists('user_id', $data) ? $data['user_id'] : $item->user_id;
        $item->name = $data['name'];
        $item->about = $data['about'] ?? null;
        $item->verified = (bool) ($data['verified'] ?? $item->verified);
        $item->save();

        return $item;
    }

    public function bulkActions(): array
    {
        return [
            'verify' => 'Verify',
            'unverify' => 'Remove verification',
        ];
    }

    public function applyBulk(Model $item, string $action, User $admin): bool
    {
        /** @var Artist $item */
        $verified = $action === 'verify';

        if ((bool) $item->verified === $verified) {
            return false;
        }

        $item->verified = $verified;

        return $item->save();
    }

    public function fields(Model $item): array
    {
        /** @var Artist $item */
        return [
            'userId' => $item->user_id,
            'name' => $item->name,
            'username' => $item->username,
            'about' => $item->about,
            'verified' => (bool) $item->verified,
            'imageUrl' => $item->files['image'] ?? null,
            'coverUrl' => $item->cover ? $item->files['cover'] : null,
        ];
    }

    protected function titleColumn(): string
    {
        return 'name';
    }

    protected function whereStatus(Builder $query, string $status): void
    {
        match ($status) {
            'verified' => $query->where('verified', true),
            'unverified' => $query->where('verified', false),
            'unclaimed' => $query->whereNull('user_id'),
        };
    }
}
