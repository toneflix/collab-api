<?php

use App\Http\Controllers\ConversationController;
use Illuminate\Support\Facades\Route;

Route::prefix('conversations')
    ->name('conversations.')
    ->group(function () {
        Route::get('{type}/{id}', [ConversationController::class, 'show'])->name('show');
        Route::get('{type}/{id}/messages', [ConversationController::class, 'messages'])->name('messages');

        Route::middleware(['auth:sanctum'])
            ->group(function () {
                Route::get('/', [ConversationController::class, 'index'])->name('index');
                Route::post('{type}/{id}/messages', [ConversationController::class, 'store'])->name('store');
                Route::delete('{type}/{id}/messages/{message}', [ConversationController::class, 'destroy'])->name('destroy');
                Route::post('{type}/{id}/read', [ConversationController::class, 'read'])->name('read');
            });
    });
