<?php

namespace App\Services\Catalog\Content;

use App\Models\Song;
use App\Models\User;
use App\Services\Composer\SongPublishing;
use App\Services\Composer\Songs;
use App\Services\Moderation\Reportables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Composer songs, shown to people as compositions.
 */
class SongType extends ContentType
{
    public function __construct(
        Reportables $reportables,
        protected Songs $songs,
        protected SongPublishing $publishing,
    ) {
        parent::__construct($reportables);
    }

    public function name(): string
    {
        return 'song';
    }

    public function model(): string
    {
        return Song::class;
    }

    public function statuses(): array
    {
        return ['published', 'private', 'taken_down'];
    }

    public function rules(?Model $item = null): array
    {
        return [
            'user_id' => [$item ? 'prohibited' : 'required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:20000'],
            'published' => ['nullable', 'boolean'],
            'allow_forks' => ['nullable', 'boolean'],
            'allow_projects' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ];
    }

    public function create(array $data, User $admin): Model
    {
        $owner = User::query()
            ->withoutGlobalScopes()
            ->findOrFail($data['user_id']);

        $song = $this->songs->create($owner, $this->text($data));

        return $this->finish($song, $data);
    }

    /**
     * Saves over whatever revision is current, keeping the old lyrics as a
     * version.
     */
    public function update(Model $item, array $data, User $admin): Model
    {
        /** @var Song $item */
        $song = $this->songs->save($item, $admin, $this->text($data), $item->revision);

        return $this->finish($song, $data);
    }

    public function bulkActions(): array
    {
        return [
            'publish' => 'Publish',
            'unpublish' => 'Unpublish',
        ];
    }

    public function applyBulk(Model $item, string $action, User $admin): bool
    {
        /** @var Song $item */
        $published = $action === 'publish';

        if ($item->isPublished() === $published) {
            return false;
        }

        $this->finish($item, ['published' => $published]);

        return true;
    }

    public function fields(Model $item): array
    {
        /** @var Song $item */
        return [
            'userId' => $item->user_id,
            'title' => $item->title,
            'body' => $item->body,
            'published' => $item->isPublished(),
            'allowForks' => (bool) $item->allow_forks,
            'allowProjects' => (bool) $item->allow_projects,
            'slug' => $item->slug,
            'imageUrl' => $item->image ? $item->images['image'] ?? null : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function text(array $data): array
    {
        return [
            'title' => $data['title'],
            'body' => $data['body'] ?? '',
        ];
    }

    /**
     * Publishing settings, once the lyrics are saved. New artwork in the
     * request's `image` field is stored when the song saves.
     *
     * @param  array<string, mixed>  $data
     */
    protected function finish(Song $song, array $data): Song
    {
        return $this->publishing->update($song, [
            'published' => (bool) ($data['published'] ?? $song->isPublished()),
            'allow_forks' => (bool) ($data['allow_forks'] ?? $song->allow_forks),
            'allow_projects' => (bool) ($data['allow_projects'] ?? $song->allow_projects),
        ]);
    }

    protected function whereStatus(Builder $query, string $status): void
    {
        match ($status) {
            'published' => $query->whereNotNull('published_at'),
            'private' => $query->whereNull('published_at'),
            'taken_down' => $query->whereNotNull('taken_down_at'),
        };
    }
}
