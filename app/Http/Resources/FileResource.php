<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $with = str($request->input('with'))->remove(' ')->explode(',');
        $user = method_exists($this->fileable, 'user') ? $this->fileable->user : null;
        $filename = str($this->meta['name'] ?? 'Unknown');

        return [
            'id' => $this->id,
            'url' => $this->when($request->user()?->id === $user?->id, $this->mediaInfo['dynamicLink']),
            'src' => $this->mediaInfo['dynamicLink'], //secureLink
            'ext' => $this->mediaInfo['ext'],
            'size' => $this->mediaInfo['size'],
            'meta' => $this->meta,
            'name' => $this->when($with->contains('ext'), $filename->append('.'.$this->mediaInfo['ext']), $filename),
            'model' => $this->model,
            'type' => $this->mediaInfo['type'],
            '_type' => str($this->fileable_type)->afterLast('\\'),
            '_type_id' => $this->fileable_id,
            'description' => $this->description,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
