<?php

namespace App\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushMessage;
use Symfony\Component\Mime\Email;

/**
 * Something that happened on the platform that a user should hear about: in
 * the app's notification list, and by email and push when their preferences
 * for its category allow it.
 *
 * Text comes from `config/messages.php` under `activity::{category}`, with a
 * subject, a body and an action label.
 */
abstract class ActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function category(): NotificationCategory;

    /**
     * The message key, like `sales.tip` for `activity::sales` > `tip`.
     */
    abstract protected function key(): string;

    /**
     * Values for the message placeholders.
     *
     * @return array<string, string|int|float>
     */
    abstract protected function params(User $notifiable): array;

    /**
     * The app path the notification opens, like `/messages/project/4`.
     */
    abstract protected function path(): string;

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return NotificationPreferences::for($notifiable)->channelsFor($this->category());
    }

    /**
     * Notifications about the same thing replace each other on a device.
     */
    protected function tag(): string
    {
        return $this->category()->value.':'.$this->path();
    }

    /**
     * @return array{subject: string, body: string, action: string, url: string}
     */
    public function content(User $notifiable): array
    {
        $params = [
            'firstname' => $notifiable->firstname ?: $notifiable->username,
            'app_name' => config('app.name'),
            ...$this->params($notifiable),
        ];
        $message = config('messages.activity::'.$this->key(), []);

        return [
            'subject' => __($message['subject'] ?? '', $params),
            'body' => __($message['body'] ?? '', $params),
            'action' => __($message['action'] ?? 'Open', $params),
            'url' => rtrim(config('app.frontend_url'), '/').$this->path(),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $content = $this->content($notifiable);
        $unsubscribe = static::unsubscribeUrl($notifiable, $this->category());

        return (new MailMessage)
            ->subject($content['subject'])
            ->view(['email', 'email-plain'], [
                'subject' => $content['subject'],
                'name' => $notifiable->firstname,
                'lines' => [
                    __('Hello :0,', [$notifiable->firstname ?: $notifiable->username]),
                    e($content['body']),
                    ['link' => $content['url'], 'title' => $content['action']],
                    config('messages.signature'),
                    __('<small>You get these emails for :0. <a href=":1">Turn them off</a> or <a href=":2">choose what you get</a>.</small>', [
                        strtolower($this->category()->title()),
                        $unsubscribe,
                        rtrim(config('app.frontend_url'), '/').'/account/notifications',
                    ]),
                ],
            ])
            ->withSymfonyMessage(function (Email $message) use ($unsubscribe) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$unsubscribe}>");
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }

    public function toWebPush(User $notifiable): WebPushMessage
    {
        $content = $this->content($notifiable);

        return (new WebPushMessage)
            ->title($content['subject'])
            ->body($content['body'])
            ->icon(rtrim(config('app.frontend_url'), '/').'/icons/favicon-128x128.png')
            ->tag($this->tag())
            ->renotify()
            ->data(['url' => $content['url'], 'category' => $this->category()->value])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }

    /**
     * What the app's notification list shows.
     *
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        $content = $this->content($notifiable);

        return [
            'title' => $content['subject'],
            'message' => $content['body'],
            'category' => $this->category()->value,
            'path' => $this->path(),
            'action' => $content['action'],
        ];
    }

    /**
     * A link that turns off email for one category, without signing in.
     */
    public static function unsubscribeUrl(User $user, NotificationCategory $category): string
    {
        return URL::signedRoute('notifications.unsubscribe', [
            'user' => $user->id,
            'category' => $category->value,
        ]);
    }
}
