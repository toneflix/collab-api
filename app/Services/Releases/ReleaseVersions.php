<?php

namespace App\Services\Releases;

use App\Models\Release;
use App\Services\Audio\TrackAudio;
use Illuminate\Database\Eloquent\Builder;

/**
 * The versions of a project's releases: what happens when one is published,
 * and retiring or restoring the others.
 */
class ReleaseVersions
{
    public function __construct(protected TrackAudio $audio) {}

    /**
     * Freezes the released audio and retires the chosen earlier versions.
     *
     * @param  array<int, int|string>  $retire
     */
    public function published(Release $release, array $retire = []): void
    {
        $release->tracks()->whereNull('audio')->whereNotNull('project_track_id')
            ->chunkById(50, fn ($tracks) => $tracks->each(fn ($track) => $this->audio->freeze($track)));

        if ($retire && $release->project_id) {
            $this->siblings($release)->whereKey($retire)->whereNull('retired_at')->update(['retired_at' => now()]);
        }
    }

    public function retire(Release $release, bool $retired = true): void
    {
        $release->retired_at = $retired ? ($release->retired_at ?? now()) : null;
        $release->saveQuietly();
    }

    /**
     * The other releases of the same project by the same owner.
     */
    protected function siblings(Release $release): Builder
    {
        return Release::query()
            ->where('project_id', $release->project_id)
            ->where('user_id', $release->user_id)
            ->whereKeyNot($release->getKey());
    }
}
