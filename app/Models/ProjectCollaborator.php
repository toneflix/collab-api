<?php

namespace App\Models;

use App\Models\Scopes\RestrictBlockedUserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([RestrictBlockedUserScope::class])]
class ProjectCollaborator extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'meta',
        'role',
        'user_id',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'accepted' => false,
        'meta' => '{}',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'meta' => \Illuminate\Database\Eloquent\Casts\AsCollection::class,
        ];
    }

    /**
     * Get the Project for the Colaborator
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the User for the Colaborator
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): Attribute
    {
        return new Attribute(
            get: fn ($value) => strtolower($value === 'custom' ? $this->meta['custom'] : $value),
        );
    }

    public function scopeAccepted(Builder $query, $accepted = true): void
    {
        $query->where('accepted', $accepted);
    }

    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }
}
