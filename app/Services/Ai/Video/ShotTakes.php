<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;

/**
 * The takes and stills of a video's shots: stores a new take as the one a
 * shot plays, and drops takes and stills nothing uses any more.
 */
class ShotTakes
{
    /** Unused takes looked at in one pass */
    protected const BATCH = 500;

    public function __construct(protected VideoStorage $storage) {}

    /**
     * Store a local clip as the shot's new take and play it. A rendered
     * singing take remembers the lines it was synced to.
     */
    public function keep(MusicVideo $video, MusicVideoShot $shot, string $local, string $source = MusicVideoTake::AI, ?float $length = null): MusicVideoTake
    {
        $synced = $source === MusicVideoTake::AI && $shot->kind === MusicVideoShot::PERFORMANCE;
        $file = $this->storage->put($video, $local, "take-{$shot->id}", 'mp4');

        $take = MusicVideoTake::query()
            ->create([
                'music_video_id' => $video->id,
                'shot_id' => $shot->id,
                'source' => $source,
                'file' => $file,
                'length' => $length ?? $shot->length(),
                'synced_from' => $synced ? $shot->start : null,
                'synced_to' => $synced ? $shot->end : null,
                'prompt' => $shot->prompt,
            ]);

        $shot
            ->forceFill(['take_id' => $take->id, 'offset' => 0, 'motion' => null])
            ->save();
        $shot->setRelation('take', $take);

        $this->prune($video);

        return $take;
    }

    /**
     * Delete the takes no shot plays and the finished video wasn't cut from,
     * keeping the newest few made for each shot that is still there.
     */
    public function prune(MusicVideo $video): void
    {
        $used = $video->takesInUse();
        $shots = $video
            ->shots()
            ->pluck('id')
            ->all();
        $unused = MusicVideoTake::query()
            ->where('music_video_id', $video->id)
            ->whereKeyNot($used)
            ->latest('id')
            ->take(self::BATCH)
            ->get();
        $kept = [];

        foreach ($unused as $take) {
            $shot = $take->shot_id;

            if ($shot && in_array($shot, $shots, true) && ($kept[$shot] ?? 0) < MusicVideoTake::KEEP) {
                $kept[$shot] = ($kept[$shot] ?? 0) + 1;

                continue;
            }

            $this->storage->delete($take->file);
            $take->delete();
        }
    }

    /**
     * Delete these stills unless a shot still shows one or the finished
     * video was cut from it.
     *
     * @param  list<?string>  $files
     */
    public function dropKeyframes(MusicVideo $video, array $files): void
    {
        $used = $video->keyframesInUse();

        foreach (array_unique(array_filter($files)) as $file) {
            if (! in_array($file, $used, true)) {
                $this->storage->delete($file);
            }
        }
    }
}
