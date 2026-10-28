<?php

namespace App\Http\Resources;

use App\Models\AgentRun;
use App\Models\AgentStep;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A content agent run for the admin page: its prompt, plan, progress and,
 * when loaded, its steps.
 *
 * @mixin AgentRun
 */
class AgentRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->plan;

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'status' => $this->status,
            'options' => [
                'publish' => (bool) $this->option('publish', false),
                'artwork' => (bool) $this->option('artwork', false),
                'maxTracks' => $this->option('max_tracks'),
                'seconds' => $this->option('seconds'),
                'label' => $this->option('label'),
            ],
            'plan' => $plan,
            'counts' => $plan ? [
                'artists' => count($plan['artists'] ?? []),
                'albums' => count($plan['albums'] ?? []),
                'tracks' => collect($plan['albums'] ?? [])->sum(fn(array $album) => count($album['tracks'] ?? [])),
                'releases' => count($plan['releases'] ?? []),
                'updates' => count($plan['updates'] ?? []),
                'songs' => count($plan['songs'] ?? []),
            ] : null,
            'progress' => $this->whenLoaded('steps', fn() => [
                'done' => $this->steps
                    ->where('status', AgentStep::DONE)
                    ->count(),
                'total' => $this->steps->count(),
            ], fn() => $this->steps_count !== null ? [
                'done' => (int) $this->done_steps_count,
                'total' => (int) $this->steps_count,
            ] : null),
            'credits' => $this->credits,
            'error' => $this->error,
            'failure' => $this->failure,
            'owner' => $this->whenLoaded('owner', fn() => [
                'id' => $this->owner->id,
                'name' => $this->owner->fullname,
                'username' => $this->owner->username,
            ]),
            'queuedBy' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
            ]),
            'steps' => $this->whenLoaded('steps', fn() => $this->steps
                ->map(fn(AgentStep $step) => (new AgentStepResource($step))->resolve($request))
                ->values()
                ->all()),
            'createdAt' => $this->created_at,
            'startedAt' => $this->started_at,
            'finishedAt' => $this->finished_at,
        ];
    }
}
