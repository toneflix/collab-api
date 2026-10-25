<?php

namespace App\Http\Resources;

use App\Models\Release;
use Illuminate\Http\Request;

/**
 * A release waiting for an admin to approve it.
 *
 * @mixin Release
 */
class ReleaseReviewResource extends ReleaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'owner' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->fullname,
                'username' => $this->user->username,
            ] : null,
            'versionOf' => $this->project?->title,
            'submittedAt' => $this->updated_at,
            'aiReview' => $this->latestAiReview ? new AiReviewResource($this->latestAiReview) : null,
        ];
    }
}
