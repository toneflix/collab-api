<?php

use App\Http\Controllers\PlaylistsController;
use App\Http\Controllers\PlaylistTracksController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('playlists', [PlaylistsController::class, 'index'])->name('playlists.index');
        Route::post('playlists', [PlaylistsController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('playlists.store');
        Route::put('playlists/{playlist}', [PlaylistsController::class, 'update'])->name('playlists.update');
        Route::delete('playlists/{playlist}', [PlaylistsController::class, 'destroy'])->name('playlists.destroy');
        Route::post('playlists/{playlist}/tracks', [PlaylistTracksController::class, 'store'])->name('playlists.tracks.store');
        Route::delete('playlists/{playlist}/tracks/{track}', [PlaylistTracksController::class, 'destroy'])->name('playlists.tracks.destroy');
    });

Route::get('playlists/{playlist}', [PlaylistsController::class, 'show'])->name('playlists.show');
Route::get('playlists/{playlist}/tracks', [PlaylistTracksController::class, 'index'])->name('playlists.tracks.index');
