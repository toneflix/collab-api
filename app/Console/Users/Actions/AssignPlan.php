<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AssignPlan extends UserAction
{
    public function label(User $user): string
    {
        return 'Put on a plan';
    }

    public function run(User $user): ?string
    {
        $plans = Plan::query()
            ->active()
            ->where('is_default', false)
            ->ordered()
            ->pluck('name', 'id');

        if ($plans->isEmpty()) {
            throw ValidationException::withMessages(['plan' => 'There are no paid plans to give.']);
        }

        $planId = select(
            label: 'Which plan?',
            options: $plans
                ->mapWithKeys(fn(string $name, int $id) => ["#{$id}" => $name])
                ->all(),
            scroll: 10,
        );
        $cycles = text(
            label: 'How many billing periods?',
            default: '1',
            validate: fn(string $value) => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 120
                ? null
                : 'Enter a number from 1 to 120.',
        );
        $reason = $this->reason();

        $plan = Plan::query()
            ->findOrFail((int) ltrim($planId, '#'));

        if (! confirm(label: "Put {$this->handle($user)} on {$plan->name} for {$plan->period((int) $cycles)}?", default: false)) {
            return null;
        }

        $this->manager->assignPlan(null, $user, $plan, (int) $cycles, $reason);

        return "{$this->handle($user)} is on {$plan->name} now.";
    }
}
