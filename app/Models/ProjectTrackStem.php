<?php

namespace App\Models;

use App\Casts\StemMeta;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Toneflix\LaravelFileable\Traits\Fileable;

class ProjectTrackStem extends Model
{
    use Fileable {
        removeFile as protected removeFileNow;
    }
    use HasFactory;
    use SoftDeletes;

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
            'meta' => StemMeta::class,
        ];
    }

    public function registerFileable()
    {
        $this->fileableLoader([
            'src' => 'stem_files',
        ], 'default', true);
    }

    /**
     * A removed stem keeps its audio while version history can still bring it
     * back; the file goes when the stem is deleted for good.
     */
    public function removeFile(string|array|null $file_field = null, string $collection = 'default')
    {
        if ($this->isForceDeleting()) {
            $this->removeFileNow($file_field, $collection);
        }
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

    /**
     * Whether the user can work on this stem's track and so get its full audio.
     */
    public function verifyAccess(?User $user): bool
    {
        return $user !== null && (bool) $this->track?->project?->canEdit($user);
    }
}
