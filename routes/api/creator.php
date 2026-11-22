<?php

use App\Http\Controllers\Creator\AiArtworkController;
use App\Http\Controllers\Creator\AiController;
use App\Http\Controllers\Creator\ArtistController;
use App\Http\Controllers\Creator\ArtistVoiceController;
use App\Http\Controllers\Creator\AudioTagController;
use App\Http\Controllers\Creator\ContentAgentController;
use App\Http\Controllers\Creator\LyricsController;
use App\Http\Controllers\Creator\MusicVideoController;
use App\Http\Controllers\Creator\MusicVideoShortController;
use App\Http\Controllers\Creator\ProjectCollaboratorController;
use App\Http\Controllers\Creator\ProjectController;
use App\Http\Controllers\Creator\ProjectTrackAssetController;
use App\Http\Controllers\Creator\ProjectTrackController;
use App\Http\Controllers\Creator\ProjectTrackMixController;
use App\Http\Controllers\Creator\ProjectTrackStemController;
use App\Http\Controllers\Creator\ProjectTrackVersionController;
use App\Http\Controllers\Creator\ReleaseController;
use App\Http\Controllers\Creator\StyleTagController;
use App\Http\Controllers\Creator\TrackCanvasController;
use App\Http\Controllers\Creator\TrackController;
use App\Http\Controllers\Creator\VideoCharacterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])
    ->prefix('creator')
    ->name('creator.')
    ->group(function () {
        Route::put('releases/{release}/retirement', [ReleaseController::class, 'retirement'])->name('releases.retirement');
        Route::apiResource('releases', ReleaseController::class);
        Route::get('tracks', [TrackController::class, 'owned'])->name('tracks.owned');
        Route::apiResource('releases/{release}/tracks', TrackController::class)->names('releases.tracks');
        Route::post('releases/generic/tracks/save', [TrackController::class, 'save'])->name('releases.save.track');
        Route::apiResource('artists', ArtistController::class)->only(['store', 'update']);

        // Artists' voices for AI music
        Route::get('voices', [ArtistVoiceController::class, 'index'])->name('voices.index');
        Route::get('artists/{artist}/voice', [ArtistVoiceController::class, 'show'])->name('artists.voice.show');
        Route::put('artists/{artist}/voice', [ArtistVoiceController::class, 'update'])->name('artists.voice.update');
        Route::delete('artists/{artist}/voice', [ArtistVoiceController::class, 'destroy'])->name('artists.voice.destroy');
        Route::post('artists/{artist}/voice/sample', [ArtistVoiceController::class, 'sample'])->middleware('throttle:10,1')->name('artists.voice.sample');
        Route::post('artists/{artist}/voice/generate', [ArtistVoiceController::class, 'generate'])->middleware('throttle:10,1')->name('artists.voice.generate');
        Route::post('artists/{artist}/voice/check', [ArtistVoiceController::class, 'check'])->middleware('throttle:10,1')->name('artists.voice.check');
        Route::post('artists/{artist}/voice/check/restart', [ArtistVoiceController::class, 'restart'])->middleware('throttle:10,1')->name('artists.voice.restart');
        Route::apiResource('projects', ProjectController::class);

        // Content agent runs of the user's agent account
        Route::get('agent/runs', [ContentAgentController::class, 'index'])->name('agent.runs.index');
        Route::post('agent/runs', [ContentAgentController::class, 'store'])->middleware('throttle:30,1')->name('agent.runs.store');
        Route::get('agent/runs/{run}', [ContentAgentController::class, 'show'])->whereNumber('run')->name('agent.runs.show');
        Route::post('agent/runs/{run}/cancel', [ContentAgentController::class, 'cancel'])->whereNumber('run')->name('agent.runs.cancel');
        Route::post('agent/runs/{run}/retry', [ContentAgentController::class, 'retry'])->whereNumber('run')->name('agent.runs.retry');
        Route::post('agent/runs/{run}/remove', [ContentAgentController::class, 'removePart'])->whereNumber('run')->name('agent.runs.remove');
        // A track must belong to its project, and a stem, asset or version to its track.
        Route::scopeBindings()->group(function () {
            Route::apiResource('projects/{project}/tracks', ProjectTrackController::class)->names('projects.tracks');
            Route::apiResource('projects/{project}/tracks/{track}/stems', ProjectTrackStemController::class);
            Route::post('projects/{project}/tracks/{track}/stems/{stem}/duplicate', [ProjectTrackStemController::class, 'duplicate'])->name('projects.tracks.stems.duplicate');
            Route::post('projects/{project}/tracks/{track}/mix', [ProjectTrackMixController::class, 'store'])->middleware('throttle:30,1')->name('projects.tracks.mix');

            // AI tools
            Route::get('projects/{project}/tracks/{track}/ai/jobs', [AiController::class, 'jobs'])->name('projects.tracks.ai.jobs');
            Route::post('projects/{project}/tracks/{track}/ai/music', [AiController::class, 'music'])->middleware('throttle:10,1')->name('projects.tracks.ai.music');
            Route::post('projects/{project}/tracks/{track}/stems/{stem}/split', [AiController::class, 'split'])->middleware('throttle:10,1')->name('projects.tracks.stems.split');
            Route::post('projects/{project}/tracks/{track}/stems/{stem}/edit', [AiController::class, 'edit'])->middleware('throttle:10,1')->name('projects.tracks.stems.edit');

            // Lyric sheet
            Route::get('projects/{project}/tracks/{track}/lyrics', [LyricsController::class, 'show'])->name('projects.tracks.lyrics.show');
            Route::put('projects/{project}/tracks/{track}/lyrics', [LyricsController::class, 'update'])->name('projects.tracks.lyrics.update');
            Route::post('projects/{project}/tracks/{track}/stems/{stem}/lyrics', [LyricsController::class, 'transcribe'])->middleware('throttle:10,1')->name('projects.tracks.stems.lyrics');

            Route::put('projects/{project}/tracks/{track}/tags', [AudioTagController::class, 'update'])->name('projects.tracks.tags.update');
            Route::put('projects/{project}/tracks/{track}/moods', [StyleTagController::class, 'update'])->name('projects.tracks.moods.update');

            Route::get('projects/{project}/tracks/{track}/versions', [ProjectTrackVersionController::class, 'index'])
                ->name('projects.tracks.versions.index');
            Route::post('projects/{project}/tracks/{track}/versions/{version}/restore', [ProjectTrackVersionController::class, 'restore'])
                ->name('projects.tracks.versions.restore');
            Route::apiResource('projects/{project}/tracks/{track}/assets', ProjectTrackAssetController::class);
        });
        Route::get('ai/artwork', [AiArtworkController::class, 'index'])->name('ai.artwork.index');
        Route::post('ai/artwork', [AiArtworkController::class, 'store'])->middleware('throttle:10,1')->name('ai.artwork.store');
        Route::get('ai/jobs/{job}', [AiController::class, 'show'])->name('ai.jobs.show');
        Route::post('ai/jobs/{job}/versions/{version}', [AiController::class, 'choose'])->whereNumber('version')->name('ai.jobs.versions.choose');
        Route::get('videos', [MusicVideoController::class, 'index'])->name('videos.index');
        Route::post('tracks/{track}/videos', [MusicVideoController::class, 'store'])->middleware('throttle:10,1')->name('tracks.videos.store');
        Route::get('videos/{video}', [MusicVideoController::class, 'show'])->name('videos.show');
        Route::delete('videos/{video}', [MusicVideoController::class, 'destroy'])->name('videos.destroy');
        Route::put('videos/{video}/shots', [MusicVideoController::class, 'saveShots'])->name('videos.shots.save');
        Route::delete('videos/{video}/edit', [MusicVideoController::class, 'discardEdits'])->name('videos.edit.discard');
        Route::put('videos/{video}/shots/{shot}', [MusicVideoController::class, 'updateShot'])->name('videos.shots.update');
        Route::post('videos/{video}/shots/{shot}/still', [MusicVideoController::class, 'redrawStill'])->middleware('throttle:10,1')->name('videos.shots.still');
        Route::post('videos/{video}/shots/{shot}/takes', [MusicVideoController::class, 'addTake'])->middleware('throttle:20,1')->name('videos.shots.takes.store');
        Route::post('videos/{video}/storyboard', [MusicVideoController::class, 'retryStoryboard'])->middleware('throttle:10,1')->name('videos.storyboard');
        Route::put('videos/{video}/lyrics', [MusicVideoController::class, 'lyrics'])->middleware('throttle:10,1')->name('videos.lyrics');
        Route::post('videos/{video}/render', [MusicVideoController::class, 'render'])->middleware('throttle:10,1')->name('videos.render');
        Route::post('videos/{video}/characters/{character}/keep', [VideoCharacterController::class, 'keep'])->middleware('throttle:20,1')->name('videos.characters.keep');
        Route::get('video-characters', [VideoCharacterController::class, 'index'])->name('video-characters.index');
        Route::post('video-characters', [VideoCharacterController::class, 'store'])->middleware('throttle:20,1')->name('video-characters.store');
        Route::put('video-characters/{character}', [VideoCharacterController::class, 'update'])->name('video-characters.update');
        Route::delete('video-characters/{character}', [VideoCharacterController::class, 'destroy'])->name('video-characters.destroy');
        Route::get('videos/{video}/shorts', [MusicVideoShortController::class, 'index'])->name('videos.shorts.index');
        Route::post('videos/{video}/shorts', [MusicVideoShortController::class, 'store'])->middleware('throttle:10,1')->name('videos.shorts.store');
        Route::get('videos/{video}/shorts/{short}', [MusicVideoShortController::class, 'show'])->name('videos.shorts.show');
        Route::delete('videos/{video}/shorts/{short}', [MusicVideoShortController::class, 'destroy'])->name('videos.shorts.destroy');
        Route::get('tracks/{track}/canvas', [TrackCanvasController::class, 'show'])
            ->name('tracks.canvas.show');
        Route::post('tracks/{track}/canvas', [TrackCanvasController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('tracks.canvas.store');
        Route::post('tracks/{track}/canvas/upload', [TrackCanvasController::class, 'upload'])
            ->middleware('throttle:10,1')
            ->name('tracks.canvas.upload');
        Route::delete('tracks/{track}/canvas', [TrackCanvasController::class, 'destroy'])
            ->name('tracks.canvas.destroy');
        Route::put('tracks/{track}/lyrics', [LyricsController::class, 'updateTrack'])->name('tracks.lyrics.update');
        Route::put('tracks/{track}/tags', [AudioTagController::class, 'updateTrack'])->name('tracks.tags.update');
        Route::put('tracks/{track}/moods', [StyleTagController::class, 'updateTrack'])->name('tracks.moods.update');
        Route::apiResource('projects/{project}/collaborators', ProjectCollaboratorController::class);
    });
