<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\Request;

/**
 * Turns off email for one category from the link in a notification email.
 * The link is signed, so it works without signing in.
 */
class NotificationUnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $user, string $category)
    {
        $category = NotificationCategory::tryFrom($category);
        $user = User::query()->withoutGlobalScopes()->find($user);

        abort_unless($category && $user, 404);

        NotificationPreferences::for($user)->update([$category->value => ['mail' => false]]);

        // Mail apps confirm one-click unsubscribes with a POST and only need a success status.
        if ($request->isMethod('post')) {
            return response()->noContent();
        }

        return redirect()->away(rtrim(config('app.frontend_url'), '/') . '/account/notifications?unsubscribed=' . $category->value);
    }
}
