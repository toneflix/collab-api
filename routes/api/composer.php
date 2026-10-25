<?php

use App\Http\Controllers\WordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:composer'])
    ->withoutMiddleware('throttle:api')
    ->prefix('words')
    ->name('words.')
    ->group(function () {
        Route::get('complete', [WordController::class, 'complete'])->name('complete');
        Route::post('syllables', [WordController::class, 'syllables'])->name('syllables');
        Route::get('{word}/rhymes', [WordController::class, 'rhymes'])->name('rhymes');
        Route::get('{word}/related', [WordController::class, 'related'])->name('related');
        Route::get('{word}/definitions', [WordController::class, 'definitions'])->name('definitions');
    });
