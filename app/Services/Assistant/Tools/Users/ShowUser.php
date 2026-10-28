<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use App\Services\Admin\UserInsights;

class ShowUser extends UserTool
{
    /** Latest audit entries shown with the account */
    public const HISTORY = 5;

    public function name(): string
    {
        return 'show_user';
    }

    public function description(): string
    {
        return 'One account in detail: profile, status, plan, content counts, earnings, AI credits, the latest admin actions on it, and what you may change on it.';
    }

    public function input(): array
    {
        return ['user' => $this->userField()];
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->user($input['user']);
        $insights = app(UserInsights::class)->for($user);

        $history = $user
            ->adminActions()
            ->with('admin')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn($entry) => [
                'action' => $entry->action,
                'reason' => $entry->reason,
                'by' => $entry->admin?->username,
                'via' => $entry->details['via'] ?? null,
                'at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            ...$this->brief($user),
            'about' => $user->about,
            'country' => $user->country,
            'insights' => $insights,
            'recentAdminActions' => $history,
            'youMay' => $this->manager()->abilities($admin, $user),
        ];
    }
}
