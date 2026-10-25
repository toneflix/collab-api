<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

class BlockResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        $with = str($request->with)
            ->remove(' ')
            ->explode(',');

        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'user_id' => $this->user_id,
            'user' => $this->when($with->contains('user'), fn() => new UserResource($this->user)),
            'model' => $this->when($with->contains('model'), fn() => $this->res($this->blockable)),
        ];
    }

    protected function res(Model $res)
    {
        $class = str($res->getMorphClass())
            ->afterLast('\\')
            ->append('Resource')
            ->prepend('\\App\\Http\\Resources\\')
            ->toString();

        return class_exists($class) ? new $class($res) : $res;
    }
}
