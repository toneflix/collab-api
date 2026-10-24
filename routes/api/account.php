<?php

use App\Helpers\Providers;
use App\Http\Controllers\Account;
use App\Http\Controllers\Account\BlockController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\NotificationSettingsController;
use App\Http\Controllers\Account\PaymentsController;
use App\Http\Controllers\NotificationRequestController;
use App\Http\Controllers\NotificationUnsubscribeController;
use App\Http\Controllers\SafetyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('account')
    ->name('account.')
    ->group(function () {
        Route::post('update/profile/picture', [Account::class, 'updateProfilePicture'])->name('update.profile.picture');
        // Before the profile routes, which take any `account/{field}`.
        Route::get('notification-settings', [NotificationSettingsController::class, 'show'])->name('notification-settings.show');
        Route::put('notification-settings', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');
        Route::apiResource('/', Account::class)->parameter('', 'field')->only(['index', 'update']);
        Route::get('purchases', [PaymentsController::class, 'purchases'])->name('purchases');
        Route::get('earnings', [PaymentsController::class, 'earnings'])->name('earnings');
        Route::get('subscription', [PaymentsController::class, 'subscription'])->name('subscription');
        Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read');
        Route::apiResource('notifications', NotificationController::class)->only(['index', 'update', 'destroy']);
        Route::post('push-subscriptions', [NotificationSettingsController::class, 'subscribe'])->name('push-subscriptions.store');
        Route::delete('push-subscriptions', [NotificationSettingsController::class, 'unsubscribe'])->name('push-subscriptions.destroy');
        Route::apiResource('/notification-requests', NotificationRequestController::class)->except(['store']);
        Route::middleware(['throttle:code-requests'])->post('request/otp', function (Request $request) {
            $user = $request->user();
            $user->sendOtpNotification();
            $msg = ($em = str($user->email))->mask('*', 1, $em->length() / 2)->prepend('A new OTP has been sent to ');

            return Providers::response()->success(['data' => [], 'message' => $msg]);
        })->name('request.otp');
    });

Route::middleware(['auth:sanctum'])
    ->name('safety.')
    ->prefix('safety')
    ->group(function () {
        Route::post('reports', [SafetyController::class, 'report'])->name('safety.report');
        Route::apiResource('blocklist', BlockController::class)->except(['show', 'delete']);
    });

// The "turn these emails off" link in notification emails
Route::match(['get', 'post'], 'notifications/unsubscribe/{user}/{category}', NotificationUnsubscribeController::class)
    ->middleware('signed')
    ->name('notifications.unsubscribe');
