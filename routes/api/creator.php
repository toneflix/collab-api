<?php

use App\Http\Controllers\Creator\AiController;
use App\Http\Controllers\Creator\ArtistController;
use App\Http\Controllers\Creator\AudioTagController;
use App\Http\Controllers\Creator\LyricsController;
use App\Http\Controllers\Creator\ProjectCollaboratorController;
use App\Http\Controllers\Creator\ProjectController;
use App\Http\Controllers\Creator\ProjectTrackAssetController;
use App\Http\Controllers\Creator\ProjectTrackController;
use App\Http\Controllers\Creator\ProjectTrackStemController;
use App\Http\Controllers\Creator\ProjectTrackVersionController;
use App\Http\Controllers\Creator\ReleaseController;
use App\Http\Controllers\Creator\TrackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('creator')->name('creator.')->group(function () {
    Route::put('releases/{release}/retirement', [ReleaseController::class, 'retirement'])->name('releases.retirement');
    Route::apiResource('releases', ReleaseController::class);
    Route::apiResource('releases/{release}/tracks', TrackController::class)->names('releases.tracks');
    Route::post('releases/generic/tracks/save', [TrackController::class, 'save'])->name('releases.save.track');
    Route::apiResource('artists', ArtistController::class)->only(['store', 'update']);
    Route::apiResource('projects', ProjectController::class);
    // A track must belong to its project, and a stem, asset or version to its track.
    Route::scopeBindings()->group(function () {
        Route::apiResource('projects/{project}/tracks', ProjectTrackController::class)->names('projects.tracks');
        Route::apiResource('projects/{project}/tracks/{track}/stems', ProjectTrackStemController::class);

        // AI tools
        Route::get('projects/{project}/tracks/{track}/ai/jobs', [AiController::class, 'jobs'])->name('projects.tracks.ai.jobs');
        Route::post('projects/{project}/tracks/{track}/ai/music', [AiController::class, 'music'])->middleware('throttle:10,1')->name('projects.tracks.ai.music');
        Route::post('projects/{project}/tracks/{track}/stems/{stem}/split', [AiController::class, 'split'])->middleware('throttle:10,1')->name('projects.tracks.stems.split');

        // Lyric sheet
        Route::get('projects/{project}/tracks/{track}/lyrics', [LyricsController::class, 'show'])->name('projects.tracks.lyrics.show');
        Route::put('projects/{project}/tracks/{track}/lyrics', [LyricsController::class, 'update'])->name('projects.tracks.lyrics.update');
        Route::post('projects/{project}/tracks/{track}/stems/{stem}/lyrics', [LyricsController::class, 'transcribe'])->middleware('throttle:10,1')->name('projects.tracks.stems.lyrics');

        Route::put('projects/{project}/tracks/{track}/tags', [AudioTagController::class, 'update'])->name('projects.tracks.tags.update');

        Route::get('projects/{project}/tracks/{track}/versions', [ProjectTrackVersionController::class, 'index'])
            ->name('projects.tracks.versions.index');
        Route::post('projects/{project}/tracks/{track}/versions/{version}/restore', [ProjectTrackVersionController::class, 'restore'])
            ->name('projects.tracks.versions.restore');
        Route::apiResource('projects/{project}/tracks/{track}/assets', ProjectTrackAssetController::class);
    });
    Route::get('ai/jobs/{job}', [AiController::class, 'show'])->name('ai.jobs.show');
    Route::put('tracks/{track}/lyrics', [LyricsController::class, 'updateTrack'])->name('tracks.lyrics.update');
    Route::put('tracks/{track}/tags', [AudioTagController::class, 'updateTrack'])->name('tracks.tags.update');
    Route::apiResource('projects/{project}/collaborators', ProjectCollaboratorController::class);
});
