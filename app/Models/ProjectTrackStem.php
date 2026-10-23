<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Toneflix\LaravelFileable\Traits\Fileable;

class ProjectTrackStem extends Model
{
    use Fileable;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'src',
        'meta',
        'title',
        'user_id',
        'stem_id',
        'track_id',
    ];

    /**
     * The model's attributes.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'meta' => '{
            "src": "",
            "name": "Unknown",
            "type": "stem",
            "gain": "0.5",
            "muted": false,
            "start": 0,
            "soloed": false,
            "startTime": 0,
            "stereoPan": 0,
            "cuein": 0,
            "customClass": null,
            "waveOutlineColor": null
        }',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => \App\Casts\StemMeta::class,
        ];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'src' => 'stem_files',
        ], 'default', true);
    }

    public static function registerEvents()
    {
        static::created(function (self $stemTrack) {
            $stemTrack['meta']['name'] = $stemTrack->id;
            $stemTrack->saveQuietly();
        });
    }

    /**
     * Get the project track that owns this ProjectTrackStem
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(ProjectTrack::class, 'track_id');
    }

    /**
     * Get the ProjectTrackStem that this ProjectTrackStem is a clone of if any
     */
    public function stem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'stem_id');
    }

    /**
     * Get the user that owns this ProjectTrackStem
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifyAccess(User $user): bool
    {
        return $this->track->project()
            ->whereUserId($user->id)
            ->orWhereHas('collaborators', function (Builder $query) use ($user) {
                $query->whereUserId($user->id);
            })->exists();
    }
}
