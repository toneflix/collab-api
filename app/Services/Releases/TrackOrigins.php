<?php

namespace App\Services\Releases;

use App\Enums\TrackOrigin;
use App\Events\AwaitingReview;
use App\Models\ProjectTrackStem;
use App\Models\Track;

/**
 * Decides how each released track was made. Studio tracks are ranked from
 * their stems; direct uploads wait for the AI moderator or an admin.
 * An admin's choice stands until they change it.
 */
class TrackOrigins
{
    public const SYSTEM = 'system';

    public const AI_MODERATOR = 'ai_moderator';

    public const ADMIN = 'admin';

    /**
     * Rank a track again after it was created or its audio changed.
     */
    public function refresh(Track $track): void
    {
        if ($track->origin_by === self::ADMIN) {
            return;
        }

        if (! $track->project_track_id) {
            $this->clear($track);
            AwaitingReview::dispatch($track);

            return;
        }

        $this->save($track, $this->fromStems($track), self::SYSTEM);
    }

    /**
     * The rank a studio track earns from its stems: mostly AI-generated audio
     * makes it agentic, otherwise any stem from a collaborator makes it a
     * collaboration.
     */
    public function fromStems(Track $track): TrackOrigin
    {
        $ownerId = $track->moderationOwnerId();

        $stems = fn() => ProjectTrackStem::query()->where('track_id', $track->project_track_id);

        $total = $stems()->count();
        $generated = $stems()
            ->whereNotNull('meta->ai->prompt')
            ->count();
        $shared = $stems()
            ->where('user_id', '!=', (int) $ownerId)
            ->exists();

        return match (true) {
            $total > 0 && $generated * 2 >= $total => TrackOrigin::AGENTIC,
            $shared => TrackOrigin::COLLABORATION,
            default => TrackOrigin::RECORDED,
        };
    }

    /**
     * Set by the AI moderator's judgement of a direct upload.
     */
    public function judged(Track $track, TrackOrigin $origin): void
    {
        if ($track->origin_by === self::ADMIN) {
            return;
        }

        $this->save($track, $origin, self::AI_MODERATOR);
    }

    /**
     * An admin's choice, or null to rank it automatically again.
     */
    public function setByAdmin(Track $track, ?TrackOrigin $origin): void
    {
        if (! $origin) {
            $track->origin_by = null;
            $this->refresh($track);

            return;
        }

        $this->save($track, $origin, self::ADMIN);
    }

    protected function save(Track $track, TrackOrigin $origin, string $by): void
    {
        $track->forceFill(['origin' => $origin->value, 'origin_by' => $by]);

        if ($track->isDirty(['origin', 'origin_by'])) {
            $track->saveQuietly();
        }
    }

    protected function clear(Track $track): void
    {
        $track->forceFill(['origin' => null, 'origin_by' => null]);

        if ($track->isDirty(['origin', 'origin_by'])) {
            $track->saveQuietly();
        }
    }
}
