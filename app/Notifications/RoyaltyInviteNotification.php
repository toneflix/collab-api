<?php

namespace App\Notifications;

use App\Models\RoyaltyInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invitation to join the royalty program, with the link to accept it.
 */
class RoyaltyInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected RoyaltyInvite $invite)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/royalty/' . $this->invite->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name');
        $subject = __('You\'re invited to the :app royalty program', ['app' => $appName]);
        $period = $this->invite->months
            ? __(' for :months months after they join', ['months' => $this->invite->months])
            : '';

        return (new MailMessage)
            ->subject($subject)
            ->view(['email', 'email-plain'], [
                'subject' => $subject,
                'name' => $this->invite->name,
                'lines' => [
                    __('Hello :0,', [e($this->invite->name)]),
                    e(__(':app invites you to its royalty program. People who join with your link earn you :rate% of what :app keeps from their payments:period.', [
                        'app' => $appName,
                        'rate' => rtrim(rtrim(number_format($this->invite->rate, 2), '0'), '.'),
                        'period' => $period,
                    ])),
                    ['link' => $this->url(), 'title' => __('Accept the invite')],
                    e(__('The invite is open until :date.', ['date' => $this->invite->expires_at->toFormattedDateString()])),
                    config('messages.signature'),
                ],
            ]);
    }
}
