<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Takedown;
use App\Models\User;

class RestoreTakedown extends ContentTool
{
    public function name(): string
    {
        return 'restore_takedown';
    }

    public function description(): string
    {
        return 'Put taken down content back, for example after an appeal. The owner is told.';
    }

    public function input(): array
    {
        return [
            'takedown' => ['required|integer|min:1', 'The takedown\'s id'],
            'resolution' => ['nullable|string|max:2000', 'A note for the owner'],
        ];
    }

    public function run(User $admin, array $input): array
    {
        $takedown = Takedown::query()
            ->findOrFail($input['takedown']);

        $this->moderation()->restore($takedown, $admin, $input['resolution'] ?? null);

        return $this->takedown($takedown);
    }
}
