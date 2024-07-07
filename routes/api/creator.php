<?php

use App\Http\Controllers\Creator\ArtistController;
use App\Http\Controllers\Creator\ProjectCollaboratorController;
use App\Http\Controllers\Creator\ProjectController;
use App\Http\Controllers\Creator\ProjectTrackAssetController;
use App\Http\Controllers\Creator\ProjectTrackController;
use App\Http\Controllers\Creator\ProjectTrackStemController;
use App\Http\Controllers\Creator\ReleaseController;
use App\Http\Controllers\Creator\TrackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('creator')->name('creator.')->group(function () {
    Route::apiResource('releases', ReleaseController::class);
    Route::apiResource('releases/{release}/tracks', TrackController::class);
    Route::post('releases/generic/tracks/save', [TrackController::class, 'save'])->name('releases.save.track');
    Route::apiResource('artists', ArtistController::class)->only(['store', 'update']);
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('projects/{project}/tracks', ProjectTrackController::class);
    Route::apiResource('projects/{project}/tracks/{track}/stems', ProjectTrackStemController::class);
    Route::apiResource('projects/{project}/tracks/{track}/assets', ProjectTrackAssetController::class);
    Route::apiResource('projects/{project}/collaborators', ProjectCollaboratorController::class);
});
