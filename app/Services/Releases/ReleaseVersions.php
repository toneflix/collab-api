<?php

namespace App\Services\Releases;

use App\Helpers\Providers;
use App\Models\Release;
use App\Models\User;
use App\Notifications\ModerationNotice;
use App\Services\Audio\TrackAudio;
use Illuminate\Database\Eloquent\Builder;

/**
 * The versions of a project's releases: what happens when one is published,
 * its review when admins approve releases, and retiring or restoring the others.
 */
class ReleaseVersions
{
    public function __construct(protected TrackAudio $audio) {}

    public static function reviewsEnabled(): bool
    {
        return (bool) Providers::config('review_releases', false);
    }

    /**
     * Freezes the released audio, then either retires the chosen earlier
     * versions or, when releases are reviewed, holds the release for an admin.
     *
     * @param  array<int, int|string>  $retire
     */
    public function published(Release $release, array $retire = []): void
    {
        $release->tracks()->whereNull('audio')->whereNotNull('project_track_id')
            ->chunkById(50, fn ($tracks) => $tracks->each(fn ($track) => $this->audio->freeze($track)));

        if (static::reviewsEnabled()) {
            $release->forceFill([
                'review_status' => Release::REVIEW_PENDING,
                'review_note' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'retire_on_approval' => array_values(array_map('intval', $retire)),
            ])->saveQuietly();

            return;
        }

        $release->forceFill(['review_status' => null, 'retire_on_approval' => null])->saveQuietly();

        $this->retireSiblings($release, $retire);
    }

    /**
     * Puts a release live and retires the versions its owner chose to replace.
     */
    public function approve(Release $release, User $admin): void
    {
        $release->forceFill([
            'review_status' => Release::REVIEW_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ])->saveQuietly();

        $this->retireSiblings($release, $release->retire_on_approval ?? []);

        $release->forceFill(['retire_on_approval' => null])->saveQuietly();

        $this->notifyOwner($release, 'Your release is live', "\"{$release->title}\" was approved and is now live.");
    }

    /**
     * Sends a release back to its owner as a draft, with the reason.
     */
    public function reject(Release $release, User $admin, string $note): void
    {
        $release->forceFill([
            'draft' => true,
            'review_status' => Release::REVIEW_REJECTED,
            'review_note' => $note,
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
            'retire_on_approval' => null,
        ])->saveQuietly();

        $this->notifyOwner($release, 'Your release needs changes', "\"{$release->title}\" was not approved: {$note}");
    }

    public function retire(Release $release, bool $retired = true): void
    {
        $release->retired_at = $retired ? ($release->retired_at ?? now()) : null;
        $release->saveQuietly();
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    protected function retireSiblings(Release $release, array $ids): void
    {
        if ($ids && $release->project_id) {
            $this->siblings($release)->whereKey($ids)->whereNull('retired_at')->update(['retired_at' => now()]);
        }
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

    protected function notifyOwner(Release $release, string $title, string $message): void
    {
        $owner = User::query()->withoutGlobalScopes()->find($release->user_id);

        $owner?->notify(new ModerationNotice($title, $message, ['release_id' => $release->id]));
    }
}
