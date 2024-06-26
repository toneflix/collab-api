<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use ToneflixCode\LaravelFileable\Traits\Fileable;

class Artist extends Model
{
    use Fileable, HasFactory, ModelCanExtend;

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'verified' => false,
        'reputation' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'reputation' => 'integer',
        ];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'avatar',
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->username ??= $model->generateUsername($model->name);
        });
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('id', $value)
            ->orWhere('username', $value)
            ->firstOrFail();
    }

    /**
     * Get all of the artists for the Track
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)
            ->using(TrackArtist::class)
            ->as('artist')
            ->withTimestamps();
    }

    /**
     * Get the user that owns the Artist
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(fn () => User::firstWhere('curator', true));
    }
}
