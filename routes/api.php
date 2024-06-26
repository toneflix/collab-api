<?php

use App\Helpers\Providers;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

if (file_exists(base_path('routes/api'))) {
    array_filter(File::files(base_path('routes/api')), function (Symfony\Component\Finder\SplFileInfo $file) {
        if ($file->getExtension() === 'php') {
            Route::middleware('api')->group($file->getPathName());
        }
    });
}

Route::get('/', function (Request $request) {
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
Route::apiResource('genres', GenreController::class)->only(['index', 'show']);
Route::apiResource('artists', ArtistController::class)->only(['index', 'show']);
Route::apiResource('releases', ReleaseController::class)->only(['index', 'show']);
Route::apiResource('releases/{release}/tracks', TrackController::class)->only(['index', 'show']);