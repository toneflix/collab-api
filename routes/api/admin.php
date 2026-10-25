<?php

use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\ConfigurationController;
use App\Http\Controllers\Admin\GenreController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\PayoutController;
use App\Http\Controllers\Admin\PayoutSettingsController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\RealtimeSettingsController;
use App\Http\Controllers\Admin\SoundSettingsController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\UserController;
use App\Models\Release;
use App\Models\Scopes\RestrictBlockedUserScope;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Admins see every account, including ones that blocked them
Route::bind('reviewRelease', fn (string $value) => Release::query()->withoutGlobalScopes()->findOrFail($value));
Route::bind('managedUser', fn (string $value) => User::query()->withoutGlobalScope(RestrictBlockedUserScope::class)->findOrFail($value));

Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    // Numbers for the admin overview
    Route::get('stats', StatsController::class)->name('stats');

    Route::apiResource('genres', GenreController::class)->except(['index']);

    // General app settings
    Route::get('configuration', [ConfigurationController::class, 'show'])->name('configuration.show');
    Route::put('configuration', [ConfigurationController::class, 'update'])->name('configuration.update');

    // Realtime driver and keys
    Route::get('realtime', [RealtimeSettingsController::class, 'show'])->name('realtime.show');
    Route::put('realtime', [RealtimeSettingsController::class, 'update'])->name('realtime.update');
    Route::post('realtime/test', [RealtimeSettingsController::class, 'test'])->name('realtime.test');

    // Sound library and Freesound
    Route::get('sounds', [SoundSettingsController::class, 'show'])->name('sounds.show');
    Route::put('sounds', [SoundSettingsController::class, 'update'])->name('sounds.update');
    Route::post('sounds/account', [SoundSettingsController::class, 'useAccount'])->name('sounds.account');
    Route::post('sounds/sync', [SoundSettingsController::class, 'sync'])->name('sounds.sync');
    Route::get('sounds/library', [SoundSettingsController::class, 'library'])->name('sounds.library');
    Route::post('sounds/library', [SoundSettingsController::class, 'store'])->name('sounds.store');
    Route::put('sounds/library/{sound}', [SoundSettingsController::class, 'updateSound'])->name('sounds.update-sound');
    Route::delete('sounds/library/{sound}', [SoundSettingsController::class, 'destroy'])->name('sounds.destroy');

    // AI tools and credit prices
    Route::get('ai', [AiSettingsController::class, 'show'])->name('ai.show');
    Route::put('ai', [AiSettingsController::class, 'update'])->name('ai.update');

    // Subscription plans and the features they can grant or limit
    Route::get('plans/features', [PlanController::class, 'features'])->name('plans.features');
    Route::apiResource('plans', PlanController::class);

    // Creators' withdrawal requests
    Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
    Route::get('payouts/settings', [PayoutSettingsController::class, 'show'])->name('payouts.settings.show');
    Route::put('payouts/settings', [PayoutSettingsController::class, 'update'])->name('payouts.settings.update');
    Route::post('payouts/{payout}/approve', [PayoutController::class, 'approve'])->name('payouts.approve');
    Route::post('payouts/{payout}/reject', [PayoutController::class, 'reject'])->name('payouts.reject');
    Route::post('payouts/{payout}/sync', [PayoutController::class, 'sync'])->name('payouts.sync');

    // Reported and flagged content, takedowns and appeals
    Route::prefix('moderation')->name('moderation.')->group(function () {
        Route::get('queue', [ModerationController::class, 'queue'])->name('queue');
        Route::get('takedowns', [ModerationController::class, 'takedowns'])->name('takedowns');
        Route::post('items/{type}/{id}/takedown', [ModerationController::class, 'takeDown'])->whereNumber('id')->name('takedown');
        Route::post('items/{type}/{id}/dismiss', [ModerationController::class, 'dismiss'])->whereNumber('id')->name('dismiss');
        Route::post('items/{type}/{id}/resolve', [ModerationController::class, 'resolve'])->whereNumber('id')->name('resolve');
        Route::post('takedowns/{takedown}/restore', [ModerationController::class, 'restore'])->name('restore');
        Route::post('takedowns/{takedown}/uphold', [ModerationController::class, 'uphold'])->name('uphold');
        Route::get('releases', [ModerationController::class, 'releases'])->name('releases');
        Route::get('claims', [ModerationController::class, 'claims'])->name('claims');
        Route::post('claims/{claim}/approve', [ModerationController::class, 'approveClaim'])->whereNumber('claim')->name('claims.approve');
        Route::post('claims/{claim}/reject', [ModerationController::class, 'rejectClaim'])->whereNumber('claim')->name('claims.reject');
        Route::get('claims/{claim}/document', [ModerationController::class, 'claimDocument'])->whereNumber('claim')->name('claims.document');
        Route::post('releases/{reviewRelease}/approve', [ModerationController::class, 'approveRelease'])->whereNumber('reviewRelease')->name('releases.approve');
        Route::post('releases/{reviewRelease}/reject', [ModerationController::class, 'rejectRelease'])->whereNumber('reviewRelease')->name('releases.reject');
    });

    // User accounts, their numbers, and what admins do to them
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('{managedUser}', [UserController::class, 'show'])->whereNumber('managedUser')->name('show');
        Route::put('{managedUser}', [UserController::class, 'update'])->whereNumber('managedUser')->name('update');
        Route::get('{managedUser}/activity', [UserController::class, 'activity'])->whereNumber('managedUser')->name('activity');
        Route::get('{managedUser}/transactions', [UserController::class, 'transactions'])->whereNumber('managedUser')->name('transactions');
        Route::post('{managedUser}/suspend', [UserController::class, 'suspend'])->whereNumber('managedUser')->name('suspend');
        Route::post('{managedUser}/unsuspend', [UserController::class, 'unsuspend'])->whereNumber('managedUser')->name('unsuspend');
        Route::post('{managedUser}/verify', [UserController::class, 'verify'])->whereNumber('managedUser')->name('verify');
        Route::put('{managedUser}/roles', [UserController::class, 'roles'])->whereNumber('managedUser')->name('roles');
        Route::post('{managedUser}/plan', [UserController::class, 'assignPlan'])->whereNumber('managedUser')->name('plan');
        Route::delete('{managedUser}/plan', [UserController::class, 'endPlan'])->whereNumber('managedUser')->name('plan.end');
        Route::post('{managedUser}/funds', [UserController::class, 'funds'])->whereNumber('managedUser')->name('funds');
        Route::post('{managedUser}/credits', [UserController::class, 'credits'])->whereNumber('managedUser')->name('credits');
    });
});
