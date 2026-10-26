<?php

use App\Http\Controllers\Creator\SongArtworkController;
use App\Http\Controllers\Creator\SongController;
use App\Http\Controllers\Creator\SongForkController;
use App\Http\Controllers\Creator\SongProjectController;
use App\Http\Controllers\Creator\SongPublishingController;
use App\Http\Controllers\Creator\SongSuggestionController;
use App\Http\Controllers\Creator\SongVersionController;
use App\Http\Controllers\PublicSongController;
use Illuminate\Support\Facades\Route;

Route::get('songs', [PublicSongController::class, 'index'])->name('songs.index');
Route::get('songs/{song:slug}', [PublicSongController::class, 'show'])->name('songs.show');

Route::middleware(['auth:sanctum', 'throttle:composer'])
    ->withoutMiddleware('throttle:api')
    ->prefix('creator')
    ->name('creator.')
    ->group(function () {
        Route::apiResource('songs', SongController::class);
        Route::post('songs/{song}/project', [SongProjectController::class, 'store'])->name('songs.project.store');
        Route::post('songs/{song}/project/lyrics', [SongProjectController::class, 'lyrics'])->name('songs.project.lyrics');
        Route::put('songs/{song}/publishing', [SongPublishingController::class, 'update'])->name('songs.publishing.update');
        Route::post('songs/{song}/artwork', [SongArtworkController::class, 'store'])->name('songs.artwork.store');
        Route::delete('songs/{song}/artwork', [SongArtworkController::class, 'destroy'])->name('songs.artwork.destroy');
        Route::post('songs/{song}/forks', [SongForkController::class, 'store'])->name('songs.forks.store');
        Route::post('songs/{song}/suggestions', [SongSuggestionController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('songs.suggestions.store');

        Route::scopeBindings()->group(function () {
            Route::get('songs/{song}/versions', [SongVersionController::class, 'index'])->name('songs.versions.index');
            Route::post('songs/{song}/versions', [SongVersionController::class, 'store'])->name('songs.versions.store');
            Route::post('songs/{song}/versions/{version}/restore', [SongVersionController::class, 'restore'])->name('songs.versions.restore');
        });
    });
