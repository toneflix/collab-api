<?php

namespace App\Policies;

use App\Models\AgentRun;
use App\Models\User;

/**
 * Who can use the content agent: admins who manage content for any agent
 * account, and the users of an agent account for its own runs.
 */
class AgentRunPolicy
{
    public static function manages(?User $user): bool
    {
        return (bool) $user?->checkPermissionTo('manage-content');
    }

    /**
     * Whether the user can list and queue runs for their own account.
     */
    public function own(User $user): bool
    {
        return (bool) $user->content_agent;
    }

    public function view(User $user, AgentRun $run): bool
    {
        return self::manages($user) || ($user->content_agent && (int) $run->owner_id === (int) $user->id);
    }

    public function update(User $user, AgentRun $run): bool
    {
        return $this->view($user, $run);
    }
}
