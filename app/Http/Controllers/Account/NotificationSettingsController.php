<?php

namespace App\Http\Controllers\Account;

use App\Enums\HttpStatus;
use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\SettingsResource;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Notifications\Fcm;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\WebPush;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which notifications a user gets by email and push, and the browsers and
 * installed apps that receive push.
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request)
    {
        return $this->settings($request->user());
    }

    public function update(Request $request)
    {
        $categories = collect(NotificationCategory::cases())->map->value
            ->all();

        $valid = $request->validate([
            'categories' => ['required', 'array'],
            'categories.*' => ['array:mail,push'],
            'categories.*.mail' => ['sometimes', 'boolean'],
            'categories.*.push' => ['sometimes', 'boolean'],
        ]);

        $values = collect($valid['categories'])
            ->only($categories)
            ->all();

        NotificationPreferences::for($request->user())->update($values);

        return $this->settings($request->user(), 'Notification settings saved.', HttpStatus::ACCEPTED);
    }

    /**
     * Save this browser's push subscription.
     */
    public function subscribe(Request $request)
    {
        abort_unless(WebPush::enabled(), HttpStatus::UNPROCESSABLE_ENTITY->value, 'Push notifications aren\'t set up on this server.');

        $valid = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        $request
            ->user()
            ->updatePushSubscription(
                $valid['endpoint'],
                $valid['keys']['p256dh'],
                $valid['keys']['auth'],
                $valid['contentEncoding'] ?? 'aes128gcm',
            );

        return $this->settings($request->user(), 'Push notifications are on for this device.', HttpStatus::CREATED);
    }

    /**
     * Stop push to this browser.
     */
    public function unsubscribe(Request $request)
    {
        $valid = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        $request
            ->user()
            ->deletePushSubscription($valid['endpoint']);

        return $this->settings($request->user(), 'Push notifications are off for this device.', HttpStatus::ACCEPTED);
    }

    /**
     * Save this installed app's Firebase Cloud Messaging token. A token moves
     * to whoever signed in on the device last.
     */
    public function registerDevice(Request $request)
    {
        abort_unless(Fcm::enabled(), HttpStatus::UNPROCESSABLE_ENTITY->value, 'Push notifications aren\'t set up on this server.');

        $valid = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(DeviceToken::PLATFORMS)],
        ]);

        DeviceToken::query()
            ->updateOrCreate(
                ['token' => $valid['token']],
                ['user_id' => $request->user()->id, 'platform' => $valid['platform']],
            );

        return $this->settings($request->user(), 'Push notifications are on for this device.', HttpStatus::CREATED);
    }

    /**
     * Stop push to this installed app.
     */
    public function forgetDevice(Request $request)
    {
        $valid = $request->validate(['token' => ['required', 'string', 'max:255']]);

        $request
            ->user()
            ->deviceTokens()
            ->where('token', $valid['token'])
            ->delete();

        return $this->settings($request->user(), 'Push notifications are off for this device.', HttpStatus::ACCEPTED);
    }

    protected function settings(User $user, string $message = 'OK', HttpStatus $status = HttpStatus::OK)
    {
        $categories = NotificationPreferences::for($user)->forClient();
        $browsers = $user
            ->pushSubscriptions()
            ->count();
        $apps = $user
            ->deviceTokens()
            ->count();

        $settings = [
            'categories' => $categories,
            'push' => WebPush::clientConfig() + [
                'nativeEnabled' => Fcm::enabled(),
                'devices' => $browsers + $apps,
            ],
        ];

        return (new SettingsResource($settings))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => $status,
            ])
            ->response()
            ->setStatusCode($status->value);
    }
}
