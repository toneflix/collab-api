<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

class NotificationRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seen' => $this->seen,
            'title' => $this->title,
            'message' => $this->message,
            'pending' => $this->pending,
            'rejected' => $this->rejected,
            'approved' => $this->approved,
            'customData' => $this->data,
            'readAt' => $this->read_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
