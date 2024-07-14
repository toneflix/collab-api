<?php

use App\Helpers\Providers;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProjectCollaboratorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\SocialInteractionController;
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
Route::apiResource('playlist', PlaylistController::class)->only(['index']);

Route::middleware(['auth:sanctum'])->group(function () {
    // Collaborators Routes
    Route::apiResource(
        'projects/{project}/collaborators',
        ProjectCollaboratorController::class
    )->except(['update', 'show']);

    foreach (['releases', 'projects', 'tracks'] as $path) {
        $param = str($path)->singular()->toString();

        // Comments routes
        Route::apiResource(
            "$path/{{$param}}/comments",
            CommentController::class
        );

        // Interaction Routes
        Route::apiResource(
            "$path/{{$param}}/interactions",
            SocialInteractionController::class
        )->only(['store', 'index']);
    }

    foreach (['users', 'artists'] as $path) {
        $param = str($path)->singular()->toString();

        // Follows Routes
        Route::apiResource("$path/{{$param}}/follows", FollowController::class)->only(['store', 'index']);
    }
});
