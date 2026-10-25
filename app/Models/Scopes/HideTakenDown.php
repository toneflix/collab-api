<?php

namespace App\Models\Scopes;

use App\Contracts\CanBeTakenDown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides taken down content from everyone but its owner.
 */
class HideTakenDown implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof CanBeTakenDown) {
            return;
        }

        $userId = auth('sanctum')->hasUser() ? (int) auth('sanctum')->id() : null;

        $builder->where(function (Builder $query) use ($model, $userId) {
            $query->whereNull($model->qualifyColumn('taken_down_at'));

            if ($userId) {
                $query->orWhere(fn(Builder $owned) => $model->whereOwnedBy($owned, $userId));
            }
        });
    }
}
