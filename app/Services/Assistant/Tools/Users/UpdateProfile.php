<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateProfile extends UserTool
{
    public function name(): string
    {
        return 'update_profile';
    }

    public function description(): string
    {
        return 'Change profile details of an account, like an offensive username or bio. Give only the fields to change.';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'changes' => ['required|array|min:1', 'Fields to change, from: firstname, lastname, username, email, phone, about, country, state, city, address'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'update');
        $changes = $this->changes($user, $input['changes']);

        $list = collect($changes)
            ->map(fn($value, $field) => "{$field} to \"" . str((string) $value)->limit(60) . '"')
            ->join(', ');

        return "Change {$this->handle($user)}'s {$list}";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'update');
        $changes = $this->changes($user, $input['changes']);

        $this->manager()->update($admin, $user, $changes);

        return $this->brief($user);
    }

    /**
     * The changes, checked with the same rules as the admin user page.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function changes(User $user, array $changes): array
    {
        $rules = $this->manager()->profileRules($user);
        $changes = Arr::only($changes, array_keys($rules));

        if (! $changes) {
            throw ValidationException::withMessages(['changes' => 'Give at least one of: ' . implode(', ', array_keys($rules)) . '.']);
        }

        return Validator::make($changes, $rules)
            ->validate();
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
