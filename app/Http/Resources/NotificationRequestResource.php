<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "seen" => $this->seen,
            "title" => $this->title,
            "message" => $this->message,
            "pending" => $this->pending,
            "rejected" => $this->rejected,
            "approved" => $this->approved,
            "custom_data" => $this->data,
            "read_at" => $this->read_at,
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
        ];
    }
}
