<?php

namespace App\Http\Resources;

use App\Enums\Feature;
use App\Models\User;
use App\Services\Ai\Credits;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * An account the content agent can build for, with the AI credits it has.
 *
 * @mixin User
 */
class AgentOwnerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->fullname,
            'username' => $this->username,
            'imageUrl' => $this->images['image'] ?? null,
            'contentAgent' => (bool) $this->content_agent,
            'aiTools' => (bool) $this->feature(Feature::AI_TOOLS),
            'credits' => Credits::for($this->resource)->total(),
        ];
    }
}
