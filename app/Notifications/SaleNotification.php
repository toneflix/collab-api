<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Helpers\Providers;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Number;

/**
 * Tells a creator someone paid them: for a release, for stems, or as a tip.
 */
class SaleNotification extends ActivityNotification
{
    protected string $purpose;

    protected string $payer;

    protected string $amount;

    protected string $title;

    protected string $note;

    public function __construct(Transaction $transaction)
    {
        $payer = $transaction->user;

        $this->purpose = $transaction->purpose;
        $this->payer = $payer ? ($payer->fullname ?: $payer->username) : __('Someone');
        $this->amount = (string) Number::currency(
            (float) ($transaction->recipient_share ?: $transaction->amount),
            (string) Providers::config('app_currency', 'USD')
        );
        $this->title = (string) ($transaction->meta['title'] ?? '');
        $this->note = (string) ($transaction->meta['message'] ?? '');

        $this->afterCommit();
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::SALES;
    }

    protected function key(): string
    {
        return 'sales.' . ($this->purpose === 'tip' && $this->note ? 'tip_note' : $this->purpose);
    }

    protected function params(User $notifiable): array
    {
        return [
            'payer' => $this->payer,
            'amount' => $this->amount,
            'title' => $this->title,
            'note' => str($this->note)->limit(140)->toString(),
        ];
    }

    protected function path(): string
    {
        return '/studio/earnings';
    }
}
