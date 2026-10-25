<?php

namespace App\Http\Resources;

use App\Models\Safety;
use App\Services\Moderation\ModerationQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A piece of content in the moderation queue with its pending reports,
 * built from a row of ModerationQueue::pending().
 *
 * @mixin Safety
 */
class ModerationItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->model_type . ':' . $this->model_id,
            'item' => app(ModerationQueue::class)->item($this->model_type, $this->model_id),
            'reportsCount' => (int) $this->reports_count,
            'matchesCount' => (int) $this->matches_count,
            'lastReportedAt' => $this->last_reported_at,
            'reasons' => (object) ($this->reason_counts ?? []),
            'reports' => SafetyResource::collection($this->whenLoaded('latestReports')),
            'takedown' => $this->whenLoaded('activeTakedown', fn() => $this->activeTakedown ? new TakedownResource($this->activeTakedown) : null),
        ];
    }
}
