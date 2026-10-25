<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

class InteractionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))
            ->remove(' ')
            ->explode(',');

        return [
            'id' => $this->id,
            'interactor' => $this->when($with->contains('interactor'), $this->res($this->interactor)),
            'interactable' => $this->when($with->contains('interactable'), $this->res($this->interactable)),
            'saved' => $this->saved,
            'votes' => $this->votes,
            'liked' => $this->liked,
            'disliked' => $this->disliked,
            'reaction' => $this->saved,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
