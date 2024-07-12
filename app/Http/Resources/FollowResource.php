<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->res($this->resource)->toArray($request);
    }

    protected function res(Model $res): JsonResource|Model
    {
        $class = str($res->getMorphClass())
            ->afterLast('\\')
            ->append('Resource')
            ->prepend('\\App\\Http\\Resources\\')
            ->toString();

        return class_exists($class) ? new $class($res) : $res;
    }
}
