<?php

namespace App\Services\Catalog;

use App\Enums\HttpStatus;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\Catalog\Content\ArtistType;
use App\Services\Catalog\Content\ContentType;
use App\Services\Catalog\Content\ProjectType;
use App\Services\Catalog\Content\ReleaseType;
use App\Services\Catalog\Content\SongType;
use App\Services\Catalog\Content\TrackType;
use App\Services\Moderation\Moderation;
use App\Services\Moderation\Reportables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The content admins list, add and edit, one ContentType per kind. Every
 * change is kept in the owner's audit trail.
 */
class ContentCatalog
{
    /** The permission the content pages need */
    public const PERMISSION = Moderation::PERMISSION;

    /**
     * @var array<string, class-string<ContentType>>
     */
    public const TYPES = [
        'track' => TrackType::class,
        'release' => ReleaseType::class,
        'song' => SongType::class,
        'project' => ProjectType::class,
        'artist' => ArtistType::class,
    ];

    public function __construct(protected Reportables $reportables) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys(self::TYPES);
    }

    public function type(string $name): ContentType
    {
        abort_unless(isset(self::TYPES[$name]), HttpStatus::NOT_FOUND->value, 'There is no such content.');

        return app(self::TYPES[$name]);
    }

    public function typeFor(Model $item): ContentType
    {
        return $this->type((string) $this->reportables->nameFor($item::class));
    }

    /**
     * One piece of content, or a 404.
     */
    public function find(string $name, int|string $id): Model
    {
        $item = $this
            ->type($name)
            ->find($id);

        abort_unless($item, HttpStatus::NOT_FOUND->value, 'That content no longer exists.');

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $name, array $data, User $admin): Model
    {
        $type = $this->type($name);

        $item = DB::transaction(fn() => $type->create($data, $admin));

        $this->audit($item, $admin, 'content.created');

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $item, array $data, User $admin): Model
    {
        $type = $this->typeFor($item);

        $item = DB::transaction(fn() => $type->update($item, $data, $admin));

        $this->audit($item, $admin, 'content.updated');

        return $item;
    }

    /**
     * Load the owners of a page of content in one query, for the list rows.
     *
     * @param  Collection<int, Model>  $items
     */
    public function withOwners(Collection $items): void
    {
        $ids = $items->mapWithKeys(fn(Model $item) => [spl_object_id($item) => $item->moderationOwnerId()]);

        $owners = User::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids->filter()->unique())
            ->get()
            ->keyBy('id');

        $items->each(fn(Model $item) => $item->setRelation('catalogOwner', $owners->get($ids[spl_object_id($item)])));
    }

    protected function audit(Model $item, User $admin, string $action): void
    {
        $owner = $item->moderationOwnerId();

        if (! $owner) {
            return;
        }

        AdminAction::query()
            ->create([
                'admin_id' => $admin->id,
                'user_id' => $owner,
                'action' => $action,
                'details' => [
                    'type' => $this->reportables->nameFor($item::class),
                    'id' => $item->getKey(),
                    'title' => $item->moderationSummary()['title'],
                ],
            ]);
    }
}
