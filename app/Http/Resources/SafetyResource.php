<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SafetyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->with)->remove(' ')->explode(',');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'reason' => $this->reason,
            'model' => $this->model,
            'user' => $this->when($with->contains('user'), fn () => new UserResource($this->user)),
        ];
    }
}
