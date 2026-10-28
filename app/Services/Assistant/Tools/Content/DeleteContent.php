<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Safety;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeleteContent extends ContentTool
{
    public function name(): string
    {
        return 'delete_content';
    }

    public function description(): string
    {
        return 'Delete a release (album), track, song (composition), project, sound or comment for good. Accounts can\'t be deleted. The admin confirms by typing the item\'s name and a code from their authenticator app on the card, so never ask them for either in the chat.';
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
        $item = $this->deletable($input);

        if (! app(TwoFactor::class)->enabled($admin)) {
            throw ValidationException::withMessages(['admin' => 'The admin must turn on two-factor sign-in in their account settings before deleting content.']);
        }

        return "Delete {$this->label($item)} for good, for {$this->moderation()->reasonLabel($input['reason'])}";
    }

    public function asks(User $admin, array $input): array
    {
        $name = $this->moderation()->deleteName($this->deletable($input));

        return [
            ['field' => 'confirm_name', 'label' => "Type “{$name}” to confirm", 'kind' => 'text'],
            ['field' => 'code', 'label' => 'Code from your authenticator app', 'kind' => 'code'],
        ];
    }

    public function run(User $admin, array $input): array
    {
        throw ValidationException::withMessages(['code' => 'The admin must confirm this on its card.']);
    }

    public function confirmWith(User $admin, array $input, array $answers): array
    {
        $item = $this->deletable($input);
        $brief = $this->brief($item);

        $this
            ->moderation()
            ->via('assistant')
            ->delete($item, $admin, $input['reason'], $input['note'] ?? null, $answers['confirm_name'] ?? null, $answers['code'] ?? null);

        return ['deleted' => $brief];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function deletable(array $input): Model
    {
        if ($input['type'] === 'user') {
            throw ValidationException::withMessages(['type' => 'Accounts can\'t be deleted. Suspend or ban the account instead.']);
        }

        return $this->item($input);
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation with the item\'s name and a two-factor code.';
    }
}
