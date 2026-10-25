<?php

namespace App\Services\Ai\Music;

use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Services\Audio\StemAudio;
use App\Services\Studio\StemFiles;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reference audio a music model follows: a stem on the track, or a file
 * uploaded with the request and kept on the uploads disk until the job
 * that uses it is done.
 */
class ReferenceAudio
{
    protected const DIRECTORY = 'ai/references';

    public function __construct(
        protected StemFiles $files,
        protected StemAudio $audio,
    ) {}

    /**
     * Check a reference is long enough and keep an upload for the job. Returns
     * what the job's input needs to find it again.
     *
     * @return array{reference_stem_id?: int, reference_file?: string}
     */
    public function keep(MusicModel $model, ProjectTrack $track, ?UploadedFile $file, mixed $stemId): array
    {
        if ($file) {
            $this->ensureLongEnough($model, $file->getRealPath());

            $name = Str::random(40) . '.' . ($file->guessExtension() === 'wav' ? 'wav' : 'mp3');

            return ['reference_file' => $this->disk()->putFileAs(self::DIRECTORY, $file, $name)];
        }

        if ($stemId) {
            /** @var ProjectTrackStem $stem */
            $stem = $track
                ->stems()
                ->findOrFail($stemId);
            $path = $this->files->local($stem) ?? throw ValidationException::withMessages(['reference_stem_id' => 'That stem has no audio.']);

            try {
                $this->ensureLongEnough($model, $path);
            } finally {
                @unlink($path);
            }

            return ['reference_stem_id' => $stem->id];
        }

        return [];
    }

    /**
     * A local copy of the job's reference, or null when it has none.
     *
     * @param  array<string, mixed>  $input
     */
    public function local(array $input, ProjectTrack $track): ?string
    {
        if (! empty($input['reference_file']) && $this->disk()->exists($input['reference_file'])) {
            $path = sys_get_temp_dir() . '/reference-' . Str::random(16) . '.' . pathinfo($input['reference_file'], PATHINFO_EXTENSION);
            file_put_contents($path, $this->disk()->readStream($input['reference_file']));

            return $path;
        }

        if (! empty($input['reference_stem_id'])) {
            $stem = $track
                ->stems()
                ->find($input['reference_stem_id']);

            return $stem ? $this->files->local($stem) : null;
        }

        return null;
    }

    /**
     * Remove an uploaded reference once its job is done.
     *
     * @param  array<string, mixed>  $input
     */
    public function forget(array $input): void
    {
        if (! empty($input['reference_file'])) {
            $this
                ->disk()
                ->delete($input['reference_file']);
        }
    }

    protected function ensureLongEnough(MusicModel $model, string $path): void
    {
        if ($this->audio->probe($path)['duration'] <= $model::REFERENCE_MIN_SECONDS) {
            throw ValidationException::withMessages([
                'reference' => 'The reference needs to be longer than ' . $model::REFERENCE_MIN_SECONDS . ' seconds.',
            ]);
        }
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.default'));
    }
}
