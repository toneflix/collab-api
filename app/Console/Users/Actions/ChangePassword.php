<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;

class ChangePassword extends UserAction
{
    public function label(User $user): string
    {
        return 'Change password';
    }

    public function run(User $user): ?string
    {
        $password = password(
            label: 'New password',
            required: true,
            validate: fn(string $value) => Validator::make(['password' => $value], ['password' => $this->manager->passwordRules()])
                ->errors()
                ->first('password') ?: null,
        );

        password(
            label: 'Type it again',
            required: true,
            validate: fn(string $value) => $value === $password ? null : 'The passwords do not match.',
        );

        $signOut = confirm(label: 'Sign them out on every device too?', default: true);
        $reason = $this->reason();

        if (! confirm(label: "Change the password of {$this->handle($user)}?", default: false)) {
            return null;
        }

        $this->manager->changePassword(null, $user, $password, $signOut, $reason);

        return "The password of {$this->handle($user)} was changed.";
    }
}
