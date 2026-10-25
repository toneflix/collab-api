<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Number;

/**
 * Tells a creator what happened to a withdrawal: paid, failed or rejected.
 */
class PayoutNotification extends ActivityNotification
{
    protected string $status;

    protected string $amount;

    protected string $bank;

    protected string $reason;

    public function __construct(Payout $payout)
    {
        $this->status = $payout->status->value;
        $this->amount = (string) Number::currency($payout->amount, $payout->currency);
        $this->bank = (string) ($payout->destination['bank_name'] ?? '');
        $this->reason = (string) $payout->failure_reason;

        $this->afterCommit();
    }

    /**
     * Whether the creator should hear about a payout in this status.
     */
    public static function covers(PayoutStatus $status): bool
    {
        return in_array($status, [PayoutStatus::PAID, PayoutStatus::FAILED, PayoutStatus::REJECTED], true);
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::PAYOUTS;
    }

    protected function key(): string
    {
        return 'payouts.' . ($this->status === PayoutStatus::REJECTED->value && $this->reason ? 'rejected_reason' : $this->status);
    }

    protected function params(User $notifiable): array
    {
        return [
            'amount' => $this->amount,
            'bank' => $this->bank,
            'reason' => str($this->reason)->limit(200)->toString(),
        ];
    }

    protected function path(): string
    {
        return '/studio/earnings';
    }
}
