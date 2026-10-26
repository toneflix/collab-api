<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\PushChannel;

/**
 * A user's choice of email and push for each notification category, kept in
 * `users.data.settings.notifications`.
 */
class NotificationPreferences
{
    public const CHANNELS = ['mail', 'push'];

    public function __construct(protected User $user) {}

    public static function for(User $user): static
    {
        return new static($user);
    }

    /**
     * Every category with its channels switched on or off.
     *
     * @return array<string, array<string, bool>>
     */
    public function all(): array
    {
        $stored = data_get($this->user->data, 'settings.notifications', []);

        // Older accounts have a single `email` switch for everything.
        $mailOff = ($stored['email'] ?? true) === false;

        $out = [];
        foreach (NotificationCategory::cases() as $category) {
            foreach (self::CHANNELS as $channel) {
                $value = $stored[$category->value][$channel] ?? null;
                $out[$category->value][$channel] = is_bool($value)
                    ? $value
                    : ! ($channel === 'mail' && $mailOff);
            }
        }

        return $out;
    }

    public function allows(NotificationCategory $category, string $channel): bool
    {
        return $this->all()[$category->value][$channel] ?? false;
    }

    /**
     * @param  array<string, array<string, bool>>  $values
     */
    public function update(array $values): void
    {
        $current = $this->all();

        foreach ($values as $category => $channels) {
            if (! NotificationCategory::tryFrom($category) || ! is_array($channels)) {
                continue;
            }

            foreach (array_intersect_key($channels, array_flip(self::CHANNELS)) as $channel => $on) {
                $current[$category][$channel] = (bool) $on;
            }
        }

        $data = $this->user->data?->all() ?? [];
        data_set($data, 'settings.notifications', array_merge(
            data_get($data, 'settings.notifications', []),
            $current
        ));

        $this->user->data = $data;
        $this->user->save();
    }

    /**
     * The Laravel channels a notification in this category goes out on. The
     * in-app list always gets it.
     *
     * @return array<int, string>
     */
    public function channelsFor(NotificationCategory $category): array
    {
        $channels = ['database'];

        if ($this->allows($category, 'mail') && filled($this->user->email)) {
            $channels[] = 'mail';
        }

        if (! $this->allows($category, 'push')) {
            return $channels;
        }

        if (WebPush::enabled() && $this->user->pushSubscriptions()->exists()) {
            $channels[] = PushChannel::class;
        }

        if (Fcm::enabled() && $this->user->deviceTokens()->exists()) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * Settings for the account page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forClient(): array
    {
        $all = $this->all();

        return collect(NotificationCategory::cases())->map(fn(NotificationCategory $c) => [
            'key' => $c->value,
            'title' => $c->title(),
            'description' => $c->description(),
            'mail' => $all[$c->value]['mail'],
            'push' => $all[$c->value]['push'],
        ])->all();
    }
}
