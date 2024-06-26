<?php

use App\Helpers\Providers;
use App\Http\Controllers\Account;
use App\Http\Controllers\NotificationRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('account')->name('account.')->group(function () {
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
