<?php

namespace App\Services\Sounds;

use App\Models\FreesoundConnection;
use App\Models\ProjectTrack;
use App\Models\ProjectTrackStem;
use App\Models\Scopes\HideTakenDown;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Toneflix\LaravelFileable\Initiator;
use Toneflix\LaravelFileable\Media;

/**
 * Stores sounds, syncs the shared library from Freesound and copies sounds
 * into tracks as stems.
 */
class SoundLibrary
{
    /**
     * Save an audio file as a sound.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function store(UploadedFile $file, array $attributes, ?User $owner = null): Sound
    {
        $name = self::put($file);

        $sound = Sound::query()
            ->create([
                'source' => 'upload',
                ...$attributes,
                'user_id' => $owner?->id,
                'file' => $name,
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ]);

        self::forgetSize();

        return $sound;
    }

    /**
     * Write a file into the sounds collection and return its new name. The
     * extension comes from the file's content when it can be told, otherwise
     * from its name.
     */
    protected static function put(UploadedFile $file): string
    {
        $media = new Media;
        $media->setCollectionDisk(Sound::COLLECTION);

        $known = ['wav', 'flac', 'aiff', 'aif', 'ogg', 'oga', 'mp3', 'mp2', 'aac', 'm4a', 'webm', 'opus', 'wma', 'amr'];
        $guessed = strtolower((string) $file->extension());
        $ext = in_array($guessed, $known, true) ? $guessed : strtolower($file->getClientOriginalExtension() ?: 'mp3');

        $name = Initiator::generateStringFromPattern(config('toneflix-fileable.file_name_pattern', '000000000-000000000')) . '.' . $ext;
        $dir = $media->getPrefix(Sound::COLLECTION) . Arr::get($media->namespaces, Sound::COLLECTION . '.path');

        $media->disk->putFileAs($dir, $file, $name);

        return $name;
    }

    /**
     * Save a downloaded file (see Freesound::download) as a sound.
     *
     * @param  array{path: string, ext: string, mime: string, quality: string, size: int}  $download
     * @param  array<string, mixed>  $attributes
     */
    public static function storeDownload(array $download, array $attributes, ?User $owner = null): Sound
    {
        try {
            $file = new UploadedFile($download['path'], 'sound.' . $download['ext'], $download['mime'], null, true);

            return self::store($file, ['quality' => $download['quality'], ...$attributes], $owner);
        } finally {
            @unlink($download['path']);
        }
    }

    /**
     * Attributes for a sound taken from a normalized Freesound result.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function freesoundAttributes(array $result, ?string $category = null): array
    {
        return [
            'source' => 'freesound',
            'source_id' => (string) $result['id'],
            'name' => str((string) $result['name'])->beforeLast('.')->limit(180, '')->toString() ?: 'Untitled',
            'category' => $category,
            'tags' => array_slice($result['tags'] ?? [], 0, 20),
            'bpm' => $result['bpm'] ?? null,
            'duration' => (float) ($result['duration'] ?? 0),
            'author' => $result['author'] ?? null,
            'license' => $result['license'] ?? null,
            'source_url' => $result['sourceUrl'] ?? null,
        ];
    }

    /**
     * Add CC0 sounds from Freesound to the shared library, for every sync
     * search. Sounds already in the library are skipped.
     *
     * @param  callable(string): void|null  $log
     * @return array{added: int, skipped: int, failed: int, paused: bool, at: string, errors: array<int, string>}
     */
    public static function sync(?callable $log = null): array
    {
        $log ??= fn() => null;
        $result = ['added' => 0, 'skipped' => 0, 'failed' => 0, 'paused' => false, 'at' => now()->toIso8601String(), 'errors' => []];

        if (! SoundSettings::freesoundConfigured()) {
            $result['errors'][] = 'Add the Freesound keys first.';

            return self::finishSync($result);
        }

        $limit = max(1, min(150, (int) SoundSettings::get('freesound_sync_limit', 25)));
        $maxDuration = (float) SoundSettings::get('freesound_sync_max_duration', 30) ?: null;
        $connection = self::libraryConnection();
        $delay = (float) config('services.freesound.download_delay', 2);

        foreach (SoundSettings::syncQueries() as ['category' => $category, 'query' => $query]) {
            $log("Searching \"{$query}\"");
            $wanted = $limit;
            $page = 1;

            while ($wanted > 0 && $page <= 5) {
                $size = min(50, max($wanted, 15));
                try {
                    $found = Freesound::search($query, $page, $size, $maxDuration);
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = "\"{$query}\": " . $e->getMessage();
                    break;
                }

                foreach ($found['results'] as $item) {
                    if ($wanted <= 0) {
                        break;
                    }

                    if (SoundSettings::syncPaused()) {
                        $result['paused'] = true;
                        $log('Paused');

                        return self::finishSync($result);
                    }

                    $exists = Sound::query()->withoutGlobalScope(HideTakenDown::class)->library()
                        ->where('source', 'freesound')
                        ->where('source_id', $item['id'])
                        ->exists();

                    if ($exists) {
                        $result['skipped']++;

                        continue;
                    }

                    try {
                        $download = Freesound::download($item, $connection);
                        self::storeDownload($download, self::freesoundAttributes($item, $category));
                        $result['added']++;
                        $wanted--;
                        $log("Added \"{$item['name']}\"");
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        $result['errors'][] = "\"{$item['name']}\": " . $e->getMessage();
                        Log::warning('Freesound sync download failed', ['id' => $item['id'], 'error' => $e->getMessage()]);
                    }

                    if ($delay > 0) {
                        usleep((int) ($delay * 1_000_000));
                    }
                }

                if (count($found['results']) === 0 || $page * $size >= $found['count']) {
                    break;
                }
                $page++;
            }
        }

        return self::finishSync($result);
    }

    /**
     * @param  array{added: int, skipped: int, failed: int, paused: bool, at: string, errors: array<int, string>}  $result
     * @return array{added: int, skipped: int, failed: int, paused: bool, at: string, errors: array<int, string>}
     */
    protected static function finishSync(array $result): array
    {
        $result['errors'] = array_slice($result['errors'], 0, 10);
        SoundSettings::update(['freesound_last_sync' => json_encode($result)]);
        Cache::forget('sounds::sync-running');
        self::forgetSize();

        return $result;
    }

    /**
     * The admin account whose Freesound connection downloads originals for the library.
     */
    public static function libraryConnection(): ?FreesoundConnection
    {
        $id = SoundSettings::get('freesound_library_account');

        return $id ? FreesoundConnection::query()->where('user_id', $id)->first() : null;
    }

    /**
     * Copy a sound into a track as a new stem, starting at `start` seconds.
     */
    public static function addToTrack(Sound $sound, ProjectTrack $track, User $user, float $start = 0): ProjectTrackStem
    {
        $media = new Media;
        $from = $sound->path();
        $ext = str($sound->file)->afterLast('.')->lower()->toString() ?: 'mp3';
        $name = Initiator::generateStringFromPattern(config('toneflix-fileable.file_name_pattern', '000000000-000000000')) . '.' . $ext;

        $media->setCollectionDisk('stem_files');
        $to = $media->getPrefix('stem_files') . Arr::get($media->namespaces, 'stem_files.path') . $name;
        $source = new Media;
        $source->setCollectionDisk(Sound::COLLECTION);
        $stream = $source->disk->readStream($from);
        abort_unless(is_resource($stream), 404, 'That sound\'s file is missing.');
        $media->disk->writeStream($to, $stream);
        fclose($stream);

        $stem = $track
            ->stems()
            ->make([
                'title' => $sound->name,
                'user_id' => $user->id,
            ]);
        $stem->src = $name;
        $stem->meta = [
            'gain' => 1,
            'start' => max(0, round($start, 3)),
            'duration' => $sound->duration ?: null,
            'sound' => array_filter([
                'id' => $sound->id,
                'source' => $sound->source,
                'author' => $sound->author,
                'license' => $sound->license,
                'url' => $sound->source_url,
            ]),
        ];
        $stem->save();

        return $stem->refresh();
    }

    public static function forgetSize(): void
    {
        Cache::forget('sounds::library-size');
    }
}
