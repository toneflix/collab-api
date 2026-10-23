<?php

namespace App\Notifications;

use App\Helpers\Providers;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use ToneflixCode\ApprovableNotifications\Models\Notification as ModelsNotification;

class JoinProjectRequestNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        protected ModelsNotification $notification,
        protected string $type = 'request',
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return Providers::config('prefered_notification_channels', ['mail', 'sms']);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $message = Providers::messageParser(
            "project::{$this->type}",
            $notifiable,
            $this->notification->actionable,
            $this->notification->actionable->project,
            [
                'sendername' => $this->notification->notifier->fullname,
            ]
        );

        return (new MailMessage())
            ->subject($message->subject)
            ->view(['email', 'email-plain'], [
                'subject' => $message->subject,
                'lines' => $message->lines,
            ]);
    }

    /**
     * Get the sms representation of the notification.
     *
     * @param  mixed  $notifiable  notifiable
     */
    public function toSms($notifiable)
    {
        $message = Providers::messageParser(
            "project::{$this->type}",
            $notifiable,
            [
                'role' => $this->notification->actionable->role,
                'title' => $this->notification->actionable->title,
                'sendername' => $this->notification->notifier->fullname,
            ]
        );

        return SmsProvider::getMessage(implode("\n", $message->lines));
    }

    public function toTwilio($n): \NotificationChannels\Twilio\TwilioSmsMessage
    {
        return $this->toSms($n);
    }

    public function toKudiSms($n): \Toneflix\KudiSmsNotification\KudiSmsMessage
    {
        return $this->toSms($n);
    }
}
