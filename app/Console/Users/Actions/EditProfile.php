<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class EditProfile extends UserAction
{
    public function label(User $user): string
    {
        return 'Edit profile details';
    }

    public function run(User $user): ?string
    {
        $rules = $this->manager->profileRules($user);
        $fields = collect($rules)
            ->keys()
            ->mapWithKeys(fn(string $field) => [$field => Str::headline($field) . ': ' . ($user->{$field} ?? '-')])
            ->put('cancel', 'Cancel');

        $field = select(
            label: 'Which detail?',
            options: $fields->all(),
            scroll: $fields->count(),
        );

        if ($field === 'cancel') {
            return null;
        }

        $value = trim(text(label: Str::headline($field), default: (string) $user->{$field}));
        $data = Validator::make([$field => $value === '' ? null : $value], [$field => $rules[$field]])
            ->validate();

        $this->manager->update(null, $user, $data);

        return Str::headline($field) . ' saved.';
    }
}
