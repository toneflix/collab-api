<?php

namespace App\Models;

use App\Traits\ModelCanExtend;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Toneflix\LaravelFileable\Media;
use Toneflix\LaravelFileable\Traits\Fileable;

class Genre extends Model
{
    use Fileable, HasFactory, ModelCanExtend;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'slug',
        'title',
        'description',
        'prompt',
    ];

    public function registerFileable()
    {
        $this->fileableLoader([
            'image' => 'artwork',
        ], 'default', true);
    }

    /**
     * What the style tagger listens for in this genre.
     */
    public function stylePrompt(): string
    {
        $article = preg_match('/^[aeiou]/i', $this->title) ? 'an' : 'a';

        return $this->prompt ?: "This is {$article} {$this->title} song.";
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function projectTracks(): HasMany
    {
        return $this->hasMany(ProjectTrack::class);
    }

    /**
     * People who picked this genre as an interest.
     */
    public function fans(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The cover photo, or null while the genre has none or its file is missing.
     */
    public function coverUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        $url = $this->files['image'];

        return $url === (new Media)->getDefaultMedia('artwork') ? null : $url;
    }

    public static function registerEvents()
    {
        static::creating(function (self $model) {
            $model->slug ??= $model->generateUsername($model->title, 'slug', '-');
        });
    }
}
