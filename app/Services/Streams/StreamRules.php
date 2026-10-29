<?php

namespace App\Services\Streams;

use App\Enums\TrackOrigin;
use App\Helpers\Providers;

/**
 * The admin's settings for paid streams.
 */
class StreamRules
{
    /**
     * @param  float  $poolShare  Percent of Premium revenue shared each month.
     * @param  array<string, float>  $weights  How much a paid stream counts, by track origin.
     * @param  int  $checkPlays  Paid streams from one listener to one creator in a month before they are checked.
     * @param  float  $checkShare  Percent of a listener's paid streams going to one creator that holds them.
     */
    public function __construct(
        public readonly float $poolShare,
        public readonly int $minSeconds,
        public readonly int $dailyCap,
        public readonly array $weights,
        public readonly int $checkPlays = 100,
        public readonly float $checkShare = 80,
    ) {}

    public static function current(): self
    {
        $weight = fn(TrackOrigin $origin, float $default) => max(0, (float) Providers::config("stream_weight_{$origin->value}", $default));

        return new self(
            max(0, min(100, (float) Providers::config('stream_pool_share', 30))),
            max(1, (int) Providers::config('stream_min_seconds', 30)),
            max(1, (int) Providers::config('stream_daily_cap', 200)),
            [
                TrackOrigin::COLLABORATION->value => $weight(TrackOrigin::COLLABORATION, 1.5),
                TrackOrigin::RECORDED->value => $weight(TrackOrigin::RECORDED, 1),
                TrackOrigin::AGENTIC->value => $weight(TrackOrigin::AGENTIC, 0.5),
            ],
            max(1, (int) Providers::config('stream_check_plays', 100)),
            max(1, min(100, (float) Providers::config('stream_check_share', 80))),
        );
    }

    /**
     * What a stream of a track with this origin counts. Unranked tracks count as agentic.
     */
    public function weight(?TrackOrigin $origin): float
    {
        return $this->weights[($origin ?? TrackOrigin::AGENTIC)->value];
    }

    /**
     * SQL for the weighted sum of plays joined to their tracks.
     */
    public function weightSql(): string
    {
        $collab = $this->weight(TrackOrigin::COLLABORATION);
        $recorded = $this->weight(TrackOrigin::RECORDED);
        $agentic = $this->weight(TrackOrigin::AGENTIC);

        return "coalesce(sum(case tracks.origin when 'collaboration' then {$collab} when 'recorded' then {$recorded} else {$agentic} end), 0)";
    }

    /**
     * @return array{poolShare: float, minSeconds: int, dailyCap: int, weights: array<string, float>, checkPlays: int, checkShare: float}
     */
    public function toArray(): array
    {
        return [
            'poolShare' => $this->poolShare,
            'minSeconds' => $this->minSeconds,
            'dailyCap' => $this->dailyCap,
            'weights' => $this->weights,
            'checkPlays' => $this->checkPlays,
            'checkShare' => $this->checkShare,
        ];
    }
}
