<?php

use App\Helpers\Providers;
use App\Http\Controllers\Account;
use App\Http\Controllers\Account\BlockController;
use App\Http\Controllers\NotificationRequestController;
use App\Http\Controllers\SafetyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('account')
    ->name('account.')
    ->group(function () {
        Route::post('update/profile/picture', [Account::class, 'updateProfilePicture'])->name('update.profile.picture');
        Route::apiResource('/', Account::class)->parameter('', 'field')->only(['index', 'update']);
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
