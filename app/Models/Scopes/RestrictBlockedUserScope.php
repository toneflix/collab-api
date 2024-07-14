<?php

namespace App\Models\Scopes;

use App\Models\Artist;
use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Models\Release;
use App\Models\User;
use BeyondCode\Comments\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

class RestrictBlockedUserScope implements Scope
{
    protected static $blockable = [
        Artist::class,
        Comment::class,
        Project::class,
        Release::class,
        ProjectCollaborator::class,
    ];

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (auth('sanctum')->hasUser()) {
            if (in_array(get_class($model), self::$blockable)) {
                $table = str(get_class($model))->afterLast('\\')->lower()->plural()->toString();

                $builder->whereNotIn("{$table}.user_id", function (QueryBuilder $query) {
                    $query->select('blocks.blockable_id')
                        ->from('blocks')
                        ->where('blocks.user_id', auth('sanctum')->id())
                        ->orWhere(function ($query) {
                            $query->where('blocks.blockable_id', auth('sanctum')->id())
                                ->where('blocks.blockable_type', User::class);
                        });
                });
            } elseif ($model instanceof User) {
                $builder->whereNotIn('users.id', function (QueryBuilder $query) {
                    $query->select('blocks.blockable_id')
                        ->from('blocks')
                        ->where('blocks.user_id', auth('sanctum')->id())
                        ->orWhere(function ($query) {
                            $query->where('blocks.blockable_id', auth('sanctum')->id())
                                ->where('blocks.blockable_type', User::class);
                        });
                });
            }
        }
    }
}
