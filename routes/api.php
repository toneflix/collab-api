<?php

use App\Helpers\Providers;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\ProjectCommentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

if (file_exists(base_path('routes/api'))) {
    array_filter(File::files(base_path('routes/api')), function (Symfony\Component\Finder\SplFileInfo $file) {
        if ($file->getExtension() === 'php') {
            Route::middleware('api')->group($file->getPathName());
        }
    });
}

Route::get('/', function () {
    return [
        'api' => config('app.name'),
    ];
});

Route::get('/initialize', function () {
    return [
        'data' => [
            'configuration' => Providers::config(),
        ],
    ];
});

Route::apiResource('users', UserController::class)->only(['index', 'show']);
Route::apiResource('tracks', TrackController::class)->only(['index', 'show']);
Route::apiResource('genres', GenreController::class)->only(['index', 'show']);
Route::apiResource('artists', ArtistController::class)->only(['index', 'show']);
Route::apiResource('explore', ExploreController::class)->only(['index']);

Route::apiResource('releases', ReleaseController::class)->only(['index', 'show']);
Route::apiResource('releases/{release}/tracks', TrackController::class)->only(['index', 'show']);

Route::apiResource('projects', ProjectController::class)->only(['index', 'show']);
Route::middleware(['auth:sanctum'])->apiResource('projects/{project}/comments', ProjectCommentController::class);
