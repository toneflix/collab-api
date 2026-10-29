<?php

namespace App\Console\Users\Actions;

use App\Console\Users\UserAction;
use App\Models\User;
use App\Services\Payment\Payouts;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class AdjustFunds extends UserAction
{
    public function label(User $user): string
    {
        return 'Add to or take from the balance';
    }

    public function run(User $user): ?string
    {
        $currency = app(Payouts::class)->currency();
        $amount = (float) text(
            label: "Amount in {$currency}",
            required: true,
            validate: fn(string $value) => is_numeric($value) && round((float) $value, 2) != 0 && abs((float) $value) <= 1000000
                ? null
                : 'Enter an amount other than zero.',
            hint: 'A negative amount takes from the balance.',
        );
        $reason = $this->reason(true);

        $verb = $amount > 0 ? "Add {$currency} {$amount} to" : "Take {$currency} " . abs($amount) . ' from';

        if (! confirm(label: "{$verb} the balance of {$this->handle($user)}?", default: false)) {
            return null;
        }

        $this->manager->adjustFunds(null, $user, $amount, $reason);

        return 'The balance was adjusted.';
    }
}
