<?php

namespace App\Services\Ai\Video;

use App\Models\MusicVideo;
use App\Models\MusicVideoShot;
use App\Models\MusicVideoTake;

/**
 * The takes of a video's shots: stores a new one as the take a shot plays,
 * and drops the oldest beyond what a shot keeps.
 */
class ShotTakes
{
    public function __construct(protected VideoStorage $storage) {}

    /**
     * Store a local clip as the shot's new take and play it. A singing take
     * remembers the lines it was synced to.
     */
    public function keep(MusicVideo $video, MusicVideoShot $shot, string $local, string $source = MusicVideoTake::AI): MusicVideoTake
    {
        $synced = $source === MusicVideoTake::AI && $shot->kind === MusicVideoShot::PERFORMANCE;
        $file = $this->storage->put($video, $local, "take-{$shot->id}", 'mp4');

        $take = MusicVideoTake::query()
            ->create([
                'music_video_id' => $video->id,
                'shot_id' => $shot->id,
                'source' => $source,
                'file' => $file,
                'length' => $shot->length(),
                'synced_from' => $synced ? $shot->start : null,
                'synced_to' => $synced ? $shot->end : null,
                'prompt' => $shot->prompt,
            ]);

        $shot
            ->forceFill(['take_id' => $take->id, 'offset' => 0, 'motion' => null])
            ->save();
        $shot->setRelation('take', $take);

        $this->prune($shot);

        return $take;
    }

    /**
     * Delete the shot's oldest takes and their files, keeping the one it
     * plays and the newest few beside it.
     */
    public function prune(MusicVideoShot $shot): void
    {
        $old = MusicVideoTake::query()
            ->where('shot_id', $shot->id)
            ->when($shot->take_id, fn($query) => $query->whereKeyNot($shot->take_id))
            ->latest('id')
            ->skip(MusicVideoTake::KEEP)
            ->take(50)
            ->get();

        foreach ($old as $take) {
            $this->storage->delete($take->file);
            $take->delete();
        }
    }
}
