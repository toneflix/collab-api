<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bank account a creator withdraws their earnings to.
 */
class PayoutAccount extends Model
{
    protected $fillable = [
        'provider',
        'country',
        'method',
        'status',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'currency',
        'recipient_code',
    ];

    protected $hidden = ['recipient_code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The account number with all but the last four digits hidden.
     */
    public function maskedNumber(): ?string
    {
        if (! $this->account_number) {
            return null;
        }

        return str($this->account_number)->mask('*', 0, -4)->toString();
    }

    /**
     * Whether payouts can be sent to it. A provider-hosted account is pending until onboarding is done.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && (bool) $this->recipient_code;
    }

    /**
     * What a payout keeps about the account it was sent to.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'provider' => $this->provider,
            'country' => $this->country,
            'method' => $this->method,
            'bank_code' => $this->bank_code,
            'bank_name' => $this->bank_name,
            'account_number' => $this->maskedNumber(),
            'account_name' => $this->account_name,
            'recipient_code' => $this->recipient_code,
        ];
    }
}
