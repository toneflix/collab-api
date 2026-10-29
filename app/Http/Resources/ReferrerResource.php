<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Toneflix\ResourceModifier\Services\Json\JsonResource;

/**
 * The person whose invite link someone opened.
 *
 * @mixin User
 */
class ReferrerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->referral_code,
            'username' => $this->username,
            'fullname' => $this->fullname,
            'imageUrl' => $this->images['image'] ?? null,
        ];
    }
}
