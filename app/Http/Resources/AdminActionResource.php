<?php

namespace App\Http\Resources;

use App\Models\AdminAction;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * @mixin AdminAction
 */
class AdminActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'reason' => $this->reason,
            'details' => $this->details ?? (object) [],
            'admin' => $this->admin ? [
                'id' => $this->admin->id,
                'username' => $this->admin->username,
                'fullname' => $this->admin->fullname,
            ] : null,
            'createdAt' => $this->created_at,
        ];
    }
}
