<?php

namespace App\Http\Resources;

use App\Models\AiReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AiReview
 */
class AiReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'decision' => $this->decision,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'note' => $this->note,
            'error' => $this->error,
            'model' => $this->model,
            'decidedAt' => $this->decided_at,
            'createdAt' => $this->created_at,
        ];
    }
}
