<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A music topic, like Heartbreak or Live music. People pick topics as
 * interests and creators tag releases with them. Anyone signed in can add
 * one, and everyone can pick it after that.
 */
class Topic extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'user_id',
    ];

    /**
     * The person who added the topic, or null for the built-in ones.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withTimestamps();
    }

    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class)
            ->withTimestamps();
    }

    /**
     * Most picked first, then the built-in ones in their listed order, then
     * the ones people added.
     */
    public function scopePopular(Builder $query): void
    {
        $query
            ->withCount('users')
            ->orderByDesc('users_count')
            ->orderByRaw('user_id is not null')
            ->orderBy('id');
    }

    public function scopeSearch(Builder $query, string $terms): void
    {
        $query->whereLike('title', '%' . trim($terms) . '%');
    }
}
