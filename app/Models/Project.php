<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ToneflixCode\LaravelFileable\Traits\Fileable;

class Project extends Model
{
    use HasFactory;
    use Fileable;
    use ModelCanExtend;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'privacy',
        'description',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'privacy' => 'private',
    ];

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'artwork',
        ], 'default', true, false);
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
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
            ->orWhere('slug', $value)
            ->firstOrFail();
    }

    /**
     * Get all of the collaborators for the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(ProjectCollaborator::class);
    }

    /**
     * Get all of the tracks for the Project
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(ProjectTrack::class);
    }

    public function ready(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->tracks()->ready()->exists(),
        );
    }
}