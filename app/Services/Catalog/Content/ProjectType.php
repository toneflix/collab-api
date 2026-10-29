<?php

namespace App\Services\Catalog\Content;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Studio projects.
 */
class ProjectType extends ContentType
{
    public function name(): string
    {
        return 'project';
    }

    public function model(): string
    {
        return Project::class;
    }

    public function statuses(): array
    {
        return ['open', 'private', 'taken_down'];
    }

    public function rules(?Model $item = null): array
    {
        return [
            'user_id' => [$item ? 'sometimes' : 'required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacy' => ['nullable', Rule::in(['private', ...Project::OPEN_PRIVACIES])],
            'tracks_public' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ];
    }

    public function create(array $data, User $admin): Model
    {
        return $this->update(new Project, $data, $admin);
    }

    /**
     * A new image in the request's `image` field is stored when the project
     * saves.
     */
    public function update(Model $item, array $data, User $admin): Model
    {
        /** @var Project $item */
        $item->user_id = $data['user_id'] ?? $item->user_id;
        $item->title = $data['title'];
        $item->description = $data['description'] ?? null;
        $item->privacy = $data['privacy'] ?? $item->privacy ?? 'private';
        $item->tracks_public = (bool) ($data['tracks_public'] ?? $item->tracks_public);
        $item->save();

        return $item;
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
        /** @var Project $item */
        $privacy = $action === 'make_public' ? 'public' : 'private';

        if ($item->privacy === $privacy) {
            return false;
        }

        $item->privacy = $privacy;

        return $item->save();
    }

    public function fields(Model $item): array
    {
        /** @var Project $item */
        return [
            'userId' => $item->user_id,
            'title' => $item->title,
            'slug' => $item->slug,
            'description' => $item->description,
            'privacy' => $item->privacy,
            'tracksPublic' => (bool) $item->tracks_public,
            'imageUrl' => $item->image ? $item->images['image'] ?? null : null,
        ];
    }

    protected function whereStatus(Builder $query, string $status): void
    {
        match ($status) {
            'open' => $query->whereIn('privacy', Project::OPEN_PRIVACIES),
            'private' => $query->where('privacy', 'private'),
            'taken_down' => $query->whereNotNull('taken_down_at'),
        };
    }
}
