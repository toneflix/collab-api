<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->data['title'],
            'message' => $this->data['message'],
            'readAt' => $this->read_at,
            'important' => $this->data['important'] ?? false,
            'category' => $this->data['category'] ?? null,
            'path' => $this->data['path'] ?? null,
            'action' => $this->data['action'] ?? null,
            'createdAt' => $this->created_at,
        ];

        if ($request->boolean('premark')) {
            $this->markAsRead();
        }

        return $data;
    }
}
