<?php

namespace App\Services\Referrals;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * What an inviter earns from the payments of people they invited.
 */
class ReferralTerms
{
    /**
     * @param  float  $rate  Percent of the platform's share of each payment.
     * @param  int  $months  How long after sign-up payments count, 0 for no end.
     */
    public function __construct(
        public readonly string $program,
        public readonly float $rate,
        public readonly int $months,
    ) {}

    /**
     * Whether a payment made at $paidAt by someone invited at $invitedAt still counts.
     */
    public function covers(DateTimeInterface $invitedAt, DateTimeInterface $paidAt): bool
    {
        if ($this->months === 0) {
            return true;
        }

        return Carbon::instance($invitedAt)
            ->addMonths($this->months)
            ->greaterThanOrEqualTo($paidAt);
    }

    /**
     * @return array{program: string, rate: float, months: int}
     */
    public function toArray(): array
    {
        return [
            'program' => $this->program,
            'rate' => $this->rate,
            'months' => $this->months,
        ];
    }
}
