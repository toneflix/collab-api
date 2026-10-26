<?php

namespace App\Models;

use App\Contracts\StoresFileMeta;
use App\Helpers\Providers;
use App\Traits\RemembersFileMeta;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Toneflix\LaravelFileable\Media;

class File extends Model implements StoresFileMeta
{
    use RemembersFileMeta;

    protected $fillable = [
        'model',
        'meta',
        'file',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'meta' => '{}',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'file_url',
        'shared_url',
    ];

    public ?int $fileIndex = null;

    /**
     * Map fileable to a collection
     *
     * @var array<class-string, string>
     *
     * @example $mediaPaths [User::class => 'avatar', Configuration::class => 'default']
     */
    public $mediaPaths = [];

    protected static function booted()
    {
        static::saving(function (File $model) {
            if (! $model->exists || ((bool) Providers::config('allow_file_updates'))) {
                $model->file = (new Media)->save($model->mediaCollection(), 'file', $model->file, $model->fileIndex);

                if (! $model->file) {
                    unset($model->file);
                }

                if (! $model->meta) {
                    unset($model->meta);
                }
            }
        });

        static::saved(fn(File $file) => $file->rememberFileMeta());

        static::deleted(function (File $file) {
            $file->file = (new Media)->delete($file->mediaCollection(), $file->file);
        });
    }

    /**
     * Get the parent fileable model (album or vision board).
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get posibly protected URL of the image.
     */
    protected function fileUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $mediaPath = $this->mediaCollection();

                $wt = '';
                if (! str($mediaPath)->exactly('default')) {
                    $wt = '?preload=true';

                    $superLoad = Auth::user()
                        ->hasRole(config('permission-defs.elevated-roles', []));
                    $uid = Auth::id() ?: 0;

                    // Check if the user has elevated access and append some random query you
                    // Can use later
                    if ($superLoad) {
                        $wt = '?preload=true&wt=' . (Auth::user()->window_token ?? rand());
                    } elseif ($this->fileable && $this->fileable->user->id === $uid) {
                        $wt = '?preload=true&wt=' . ($this->fileable->user->window_token ?? rand());
                    }

                    // Append more queries you can use later
                    $wt .= '&ctx=' . rand();
                    $wt .= '&mode=' . config('app.env');
                    $wt .= '&pov=' . md5($this->file);
                }

                return (new Media)->getMedia($mediaPath, $this->file) . $wt;
            },
        );
    }

    public function progress(): Attribute
    {
        return new Attribute(
            get: function () {
                $progress = 0;

                if ($this->fileable->processed_at) {
                    return 100;
                }

                return $progress;
            },
        );
    }

    public function progressComplete(): Attribute
    {
        return new Attribute(
            get: fn() => $this->progress >= 100 ? '100% completed!' : "{$this->progress}% processing...",
        );
    }

    /**
     * Get a shared/public URL of the image.
     *
     * @return string
     */
    protected function sharedUrl(): Attribute
    {
        return Attribute::make(
            get: function () {
                $mediaPath = $this->mediaCollection();

                $wt = '';
                if (! str($mediaPath)->exactly('default')) {
                    $window_token = Auth::user() ? Auth::user()->window_token : 0;

                    // Append some query params you can use later
                    $wt = '?preload=true&shared&wt=' . $window_token;
                    $wt .= '&ctx=' . rand();
                    $wt .= '&mode=' . config('app.env');
                    $wt .= '&pov=' . md5($this->file);
                }

                return (new Media)->getMedia($mediaPath, $this->file) . $wt;
            },
        );
    }

    /**
     * Get the file's metadata.
     *
     * @return string
     */
    public function mediaInfo(): Attribute
    {
        return Attribute::make(
            get: function () {
                $mediaPath = $this->mediaCollection();

                $info = (new Media)->mediaInfo($mediaPath, $this->file);

                return [
                    ...$info,
                    'size' => $this->fileSize('file') ?? $info['size'],
                    'mime' => $this->fileMime('file') ?? $info['mime'],
                ];
            },
        );
    }

    /**
     * The fileable collection this file is stored in.
     */
    public function mediaCollection(): string
    {
        $metaType = isset($this->meta['type']) ? ".{$this->meta['type']}" : '';

        return $this->mediaPaths[$this->fileable_type . $metaType] ?? 'default';
    }

    public function fileMetaFields(): array
    {
        return ['file' => $this->mediaCollection()];
    }
}
