<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class HideReportedModels implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (method_exists($model, 'reports') && auth('sanctum')->hasUser()) {
            $builder->whereDoesntHave('reports', function (Builder $query) {
                $query->where('user_id', auth('sanctum')->id());
            });
        }
    }
}
