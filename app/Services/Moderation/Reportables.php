<?php

namespace App\Services\Moderation;

use App\Contracts\Reportable;
use App\Models\Comment;
use App\Models\Release;
use App\Models\Sound;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The kinds of content people can report, by the name the API uses.
 */
class Reportables
{
    /**
     * @var array<string, class-string<Model&Reportable>>
     */
    public const TYPES = [
        'release' => Release::class,
        'track' => Track::class,
        'sound' => Sound::class,
        'comment' => Comment::class,
        'user' => User::class,
    ];

    /**
     * The columns moderators can search each kind of content by.
     *
     * @var array<string, list<string>>
     */
    public const SEARCH_COLUMNS = [
        'release' => ['title', 'version'],
        'track' => ['title'],
        'sound' => ['name'],
        'comment' => ['comment'],
        'user' => ['firstname', 'lastname', 'username', 'email'],
    ];

    /**
     * Limit rows that point at content (model_type, model_id) to content
     * whose text matches the search.
     */
    public function whereContentMatches(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search) {
            foreach (self::TYPES as $name => $class) {
                $model = new $class;
                $ids = $class::query()
                    ->withoutGlobalScopes()
                    ->select($model->qualifyColumn($model->getKeyName()))
                    ->where(function (Builder $q) use ($name, $model, $search) {
                        foreach (self::SEARCH_COLUMNS[$name] as $column) {
                            $q->orWhereLike($model->qualifyColumn($column), "%{$search}%");
                        }
                    });

                $query->orWhere(fn (Builder $q) => $q
                    ->where('model_type', $model->getMorphClass())
                    ->whereIn('model_id', $ids));
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * @return class-string<Model&Reportable>|null
     */
    public function classFor(string $type): ?string
    {
        return self::TYPES[$type] ?? (in_array($type, self::TYPES, true) ? $type : null);
    }

    public function nameFor(string $class): ?string
    {
        return array_search($class, self::TYPES, true) ?: null;
    }

    /**
     * Find content by type and id, whether it's taken down or hidden.
     *
     * @return (Model&Reportable)|null
     */
    public function find(string $type, int|string $id): ?Model
    {
        $class = $this->classFor($type);

        return $class ? $class::query()->withoutGlobalScopes()->find($id) : null;
    }
}
