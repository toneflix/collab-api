<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Takedown;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpholdTakedown extends ContentTool
{
    public function name(): string
    {
        return 'uphold_takedown';
    }

    public function description(): string
    {
        return 'Decline an owner\'s appeal so the content stays down. The owner is told.';
    }

    public function input(): array
    {
        return [
            'takedown' => ['required|integer|min:1', 'The appealed takedown\'s id'],
            'resolution' => ['nullable|string|max:2000', 'A note for the owner'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $takedown = $this->appealed($input);
        $item = $takedown->item();

        return 'Decline the appeal on ' . ($item ? $this->label($item) : "takedown #{$takedown->id}") . ' so it stays down';
    }

    public function run(User $admin, array $input): array
    {
        $takedown = $this->appealed($input);

        $this->moderation()->uphold($takedown, $admin, $input['resolution'] ?? null);

        return $this->takedown($takedown);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function appealed(array $input): Takedown
    {
        $takedown = Takedown::query()
            ->find($input['takedown']);

        if ($takedown?->status !== Takedown::APPEALED) {
            throw ValidationException::withMessages(['takedown' => "Takedown #{$input['takedown']} has no open appeal."]);
        }

        return $takedown;
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
