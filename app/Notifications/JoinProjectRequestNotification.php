<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\ProjectCollaborator;
use App\Models\User;

/**
 * A collab invitation or request, or the answer to one.
 *
 * Types: `request`, `invitation`, `request_approved`, `invitation_accepted`.
 */
class JoinProjectRequestNotification extends ActivityNotification
{
    protected string $sender;

    protected string $project;

    protected int|string $projectId;

    protected string $role;

    public function __construct(ProjectCollaborator $collaborator, User $sender, protected string $type = 'request')
    {
        $this->sender = $sender->fullname ?: $sender->username;
        $this->project = (string) $collaborator->project?->title;
        $this->projectId = $collaborator->project?->slug ?: $collaborator->project_id;
        $this->role = (string) $collaborator->role;

        $this->afterCommit();
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::COLLABS;
    }

    protected function key(): string
    {
        return 'collabs.' . $this->type;
    }

    protected function params(User $notifiable): array
    {
        return [
            'sendername' => $this->sender,
            'title' => $this->project,
            'role' => $this->role,
        ];
    }

    protected function path(): string
    {
        return in_array($this->type, ['request', 'invitation'], true)
            ? '/notifications?tab=requests'
            : '/studio/projects/' . $this->projectId;
    }
}
