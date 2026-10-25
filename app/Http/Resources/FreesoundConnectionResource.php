<?php

namespace App\Http\Resources;

use App\Models\FreesoundConnection;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * A person's Freesound connection: whether it is connected, the account's
 * username, and where to send them back to in the app.
 *
 * @mixin FreesoundConnection
 */
class FreesoundConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'connected' => $this->resource !== null && $this->resource->exists,
            'username' => $this->resource?->username,
        ];
    }
}
