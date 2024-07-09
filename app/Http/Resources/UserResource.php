<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $iam_admin = $request?->user()?->hasAnyRole(config('permission.admin_roles')) ||
            $this->hasAnyPermission(['manage-users', 'manage-admins']) ||
            false;

        $previleged = auth()?->id() === $this->id || $iam_admin;

        $permissions = [];
        if ($previleged) {
            $permQuery = $this->getPermissionsViaRoles()->pluck('name')->unique();
            $permissions = $permQuery->count() ? $permQuery->values() : $this->getPermissionNames();
        }

        return [
            'id' => $this->id,
            'name' => $this->fullname,
            'type' => 'user',
            'email' => $this->email,
            'phone' => $this->phone,
            'about' => $this->about,
            'imageUrl' => $this->images['image'],
            'fullname' => $this->fullname,
            'username' => $this->username,
            'lastname' => $this->lastname,
            'firstname' => $this->firstname,
            'reputation' => $this->reputation ?: rand(10, 9999),
            'projectsCount' => $this->projects_count,
            'isProjectCreator' => $this->when($request->has('project_id'), $this->is_project_creator),
            'youFollow' => $request->user('sanctum') ? $this->isFollowedBy($request->user('sanctum')) : false,
            'country' => $this->country,
            'state' => $this->state,
            $this->mergeWhen($previleged, fn () => [
                'city' => $this->city,
                'address' => $this->address,
                'roles' => $this->getRoleNames(),
                'userData' => $this->user_data,
                'permissions' => $permissions,
                'lastAttempt' => $this->last_attempt,
                'emailVerifiedAt' => $this->email_verified_at,
                'phoneVerifiedAt' => $this->phone_verified_at,
            ]),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
