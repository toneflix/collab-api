<?php

namespace App\Enums;

enum PayoutStatus: string
{
    /** Waiting for an admin to approve it */
    case PENDING = 'pending';

    /** Sent to Paystack, waiting for the bank */
    case PROCESSING = 'processing';

    case PAID = 'paid';

    case FAILED = 'failed';

    case REJECTED = 'rejected';

    case CANCELLED = 'cancelled';

    /**
     * Statuses whose amount is taken out of the creator's balance.
     *
     * @return array<int, string>
     */
    public static function holding(): array
    {
        return [self::PENDING->value, self::PROCESSING->value, self::PAID->value];
    }

    /**
     * Whether nothing more can happen to a payout in this status.
     */
    public function final(): bool
    {
        return ! in_array($this, [self::PENDING, self::PROCESSING], true);
    }
}
