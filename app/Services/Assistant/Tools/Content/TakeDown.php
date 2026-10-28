<?php

namespace App\Services\Assistant\Tools\Content;

use App\Contracts\CanBeTakenDown;
use App\Models\Safety;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TakeDown extends ContentTool
{
    public function name(): string
    {
        return 'take_down';
    }

    public function description(): string
    {
        return 'Take a release (album), track, song (composition), sound or comment down. Its pending reports close, and the owner and reporters are told.';
    }

    public function input(): array
    {
        return [
            ...$this->itemFields(),
            'reason' => [['required', Rule::in(Safety::$allowedReasons)], 'One of ' . implode(', ', Safety::$allowedReasons)],
            'note' => ['nullable|string|max:2000', 'A note for the owner'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $item = $this->takeable($input);

        return "Take down {$this->label($item)} for {$this->moderation()->reasonLabel($input['reason'])}";
    }

    public function run(User $admin, array $input): array
    {
        $item = $this->takeable($input);
        $takedown = $this->moderation()->takeDown($item, $admin, $input['reason'], $input['note'] ?? null);

        return $this->takedown($takedown);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function takeable(array $input): Model&CanBeTakenDown
    {
        $item = $this->item($input);

        if (! $item instanceof CanBeTakenDown) {
            throw ValidationException::withMessages(['type' => "A {$input['type']} can't be taken down. Suspend the account instead."]);
        }

        return $item;
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
