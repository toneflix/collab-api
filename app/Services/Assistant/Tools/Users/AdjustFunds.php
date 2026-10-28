<?php

namespace App\Services\Assistant\Tools\Users;

use App\Models\User;
use App\Services\Payment\Payouts;

class AdjustFunds extends UserTool
{
    public function name(): string
    {
        return 'adjust_funds';
    }

    public function description(): string
    {
        return 'Add to a creator\'s withdrawable balance, or take from it with a negative amount, in the app currency.';
    }

    public function input(): array
    {
        return [
            'user' => $this->userField(),
            'amount' => ['required|numeric|between:-99999999,99999999|not_in:0', 'Amount to add, negative to take away'],
            'reason' => ['required|string|max:255', 'Why, for the audit trail and the creator'],
        ];
    }

    public function permissions(): array
    {
        return ['manage-users', 'manage-payouts'];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $user = $this->authorized($admin, $input, 'funds');
        $amount = (float) $input['amount'];
        $money = app(Payouts::class)->currency() . ' ' . number_format(abs($amount), 2);

        return $amount > 0
            ? "Add {$money} to the balance of {$this->handle($user)}: {$input['reason']}"
            : "Take {$money} from the balance of {$this->handle($user)}: {$input['reason']}";
    }

    public function run(User $admin, array $input): array
    {
        $user = $this->authorized($admin, $input, 'funds');

        $this->manager()->adjustFunds($admin, $user, (float) $input['amount'], $input['reason']);

        return $this->brief($user);
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
