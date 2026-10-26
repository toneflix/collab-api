<?php

use App\Helpers\Providers;
use App\Http\Controllers\Account;
use App\Http\Controllers\Account\AiCreditsController;
use App\Http\Controllers\Account\BlockController;
use App\Http\Controllers\Account\InterestsController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\NotificationSettingsController;
use App\Http\Controllers\Account\PaymentsController;
use App\Http\Controllers\Account\PayoutAccountController;
use App\Http\Controllers\Account\PayoutController;
use App\Http\Controllers\Account\TakedownController;
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
        Route::get('interests', [InterestsController::class, 'show'])->name('interests.show');
        Route::put('interests', [InterestsController::class, 'update'])->name('interests.update');
        Route::get('ai', [AiCreditsController::class, 'show'])->name('ai.show');
        Route::get('ai/history', [AiCreditsController::class, 'history'])->name('ai.history');
        Route::apiResource('/', Account::class)->parameter('', 'field')->only(['index', 'update']);
        Route::get('purchases', [PaymentsController::class, 'purchases'])->name('purchases');
        Route::get('earnings', [PaymentsController::class, 'earnings'])->name('earnings');
        Route::get('subscription', [PaymentsController::class, 'subscription'])->name('subscription');
        Route::get('takedowns', [TakedownController::class, 'index'])->name('takedowns.index');
        Route::post('takedowns/{takedown}/appeal', [TakedownController::class, 'appeal'])->middleware('throttle:5,1')->name('takedowns.appeal');

        // Withdrawing earnings
        Route::get('payouts/countries', [PayoutAccountController::class, 'countries'])->name('payouts.countries');
        Route::get('payouts/banks', [PayoutAccountController::class, 'banks'])->name('payouts.banks');
        Route::get('payouts/account', [PayoutAccountController::class, 'show'])->name('payouts.account.show');
        Route::post('payouts/account/resolve', [PayoutAccountController::class, 'resolve'])->middleware('throttle:20,1')->name('payouts.account.resolve');
        Route::put('payouts/account', [PayoutAccountController::class, 'update'])->middleware('throttle:10,1')->name('payouts.account.update');
        Route::post('payouts/account/connect', [PayoutAccountController::class, 'connect'])->middleware('throttle:10,1')->name('payouts.account.connect');
        Route::delete('payouts/account', [PayoutAccountController::class, 'destroy'])->name('payouts.account.destroy');
        Route::apiResource('payouts', PayoutController::class)->only(['index', 'store', 'destroy']);

        Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read');
        Route::apiResource('notifications', NotificationController::class)->only(['index', 'update', 'destroy']);
        Route::post('push-subscriptions', [NotificationSettingsController::class, 'subscribe'])->name('push-subscriptions.store');
        Route::delete('push-subscriptions', [NotificationSettingsController::class, 'unsubscribe'])->name('push-subscriptions.destroy');
        Route::post('device-tokens', [NotificationSettingsController::class, 'registerDevice'])->name('device-tokens.store');
        Route::delete('device-tokens', [NotificationSettingsController::class, 'forgetDevice'])->name('device-tokens.destroy');
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
        Route::post('reports', [SafetyController::class, 'report'])->middleware('throttle:10,1')->name('safety.report');
        Route::apiResource('blocklist', BlockController::class)->except(['show', 'delete']);
    });

// The "turn these emails off" link in notification emails
Route::match(['get', 'post'], 'notifications/unsubscribe/{user}/{category}', NotificationUnsubscribeController::class)
    ->middleware('signed')
    ->name('notifications.unsubscribe');
