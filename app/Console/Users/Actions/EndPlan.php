<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use App\Services\Payment\Subscriptions;

use function Laravel\Prompts\confirm;

class EndPlan extends UserAction
{
    public function label(User $user): string
    {
        return 'Remove the plan';
    }

    public function available(User $user): bool
    {
        return app(Subscriptions::class)->current($user) !== null;
    }

    public function run(User $user): ?string
    {
        $reason = $this->reason();

        if (! confirm(label: "Put {$this->handle($user)} back on the default plan?", default: false)) {
            return null;
        }

        $this->manager->endPlan(null, $user, $reason);

        return 'The account is back on the default plan.';
    }
}
