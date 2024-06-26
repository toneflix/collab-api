<?php

use App\Http\Controllers\Admin\GenreController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('admin')->name('admin.')->group(function () {
    Route::apiResource('genres', GenreController::class)->except(['index']);
});
