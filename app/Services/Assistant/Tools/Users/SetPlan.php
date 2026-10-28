<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SetPlan extends UserTool
{
    public function name(): string
    {
        return 'set_plan';
    }

    public function description(): string
    {
        return 'Put an account on a subscription plan for some billing cycles, or back on the default plan with plan "none".';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'plan' => ['required|string|max:100', 'The plan\'s id, slug or name, or "none" to remove the current plan'],
            'cycles' => ['nullable|integer|min:1|max:120', 'Billing cycles to give, 1 by default'],
            'reason' => ['nullable|string|max:255', 'Why, for the audit trail'],
        ];
    }

    public function permissions(): array
    {
        return ['manage-users', 'manage-plans'];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'plan');
        $plan = $this->plan($input['plan']);
        $cycles = (int) ($input['cycles'] ?? 1);

        return $plan
            ? "Put {$this->handle($user)} on {$plan->name} for {$plan->period($cycles)}"
            : "Take {$this->handle($user)} off their plan, back to the default plan";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'plan');
        $plan = $this->plan($input['plan']);

        $plan
            ? $this->manager()->assignPlan($admin, $user, $plan, (int) ($input['cycles'] ?? 1), $input['reason'] ?? null)
            : $this->manager()->endPlan($admin, $user, $input['reason'] ?? null);

        return $this->brief($user);
    }

    /**
     * The plan asked for, or null for "none".
     */
    protected function plan(string $key): ?Plan
    {
        if (strtolower(trim($key)) === 'none') {
            return null;
        }

        $plan = Plan::query()
            ->where(fn(Builder $q) => $q
                ->where('slug', $key)
                ->orWhereRaw('LOWER(name) = ?', [strtolower($key)])
                ->when(ctype_digit($key), fn(Builder $q) => $q->orWhere('id', (int) $key)))
            ->first();

        if (! $plan) {
            $names = Plan::query()
                ->orderBy('sort')
                ->limit(20)
                ->pluck('name')
                ->join(', ');

            throw ValidationException::withMessages(['plan' => "No plan matches \"{$key}\". Plans: {$names}."]);
        }

        return $plan;
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
