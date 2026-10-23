<?php

use App\Http\Controllers\Admin\GenreController;
use App\Http\Controllers\Admin\PlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    Route::apiResource('genres', GenreController::class)->except(['index']);

    // Subscription plans and the features they can grant or limit
    Route::get('plans/features', [PlanController::class, 'features'])->name('plans.features');
    Route::apiResource('plans', PlanController::class);
});
