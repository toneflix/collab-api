<?php

namespace App\Http\Resources;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user account as admins see it.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $plan = $this->relationLoaded('subscriptions')
            ? ($this->subscriptions->first()?->planModel ?? Plan::default())
            : null;

        return [
            'id' => $this->id,
            'username' => $this->username,
            'fullname' => $this->fullname,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'imageUrl' => $this->images['image'] ?? null,
            'roles' => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')),
            'verified' => (bool) $this->verified,
            'emailVerifiedAt' => $this->email_verified_at,
            'suspended' => $this->isSuspended(),
            'suspendedAt' => $this->when($this->isSuspended(), $this->suspended_at),
            'suspendedUntil' => $this->when($this->isSuspended(), $this->suspended_until),
            'suspensionReason' => $this->when($this->isSuspended(), $this->suspension_reason),
            'plan' => $plan ? ['id' => $plan->id, 'name' => $plan->name, 'isDefault' => (bool) $plan->is_default] : null,
            'projectsCount' => (int) $this->projects_count,
            'releasesCount' => $this->whenCounted('releases'),
            'about' => $this->about,
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'address' => $this->address,
            'createdAt' => $this->created_at,
        ];
    }
}
