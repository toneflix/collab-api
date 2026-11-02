<?php

use App\Enums\AccountType;
use App\Helpers\Providers;
use App\Http\Controllers\Account\ReferralController;
use App\Http\Controllers\ArtistClaimController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\Creator\AiArtworkController;
use App\Http\Controllers\Creator\AiController;
use App\Http\Controllers\Creator\ArtistVoiceController;
use App\Http\Controllers\Creator\MusicVideoController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\FaustEffectController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\GenreController;
use App\Http\Controllers\LyricsController;
use App\Http\Controllers\Payment\PaymentController;
use App\Http\Controllers\Payment\PayoutWebhookController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProjectCollaboratorController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\RoyaltyInviteController;
use App\Http\Controllers\SocialInteractionController;
use App\Http\Controllers\Studio\MixRenderController;
use App\Http\Controllers\SunoCallbackController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\TrackCreditsController;
use App\Http\Controllers\UserController;
use App\Services\Notifications\WebPush;
use App\Services\Realtime\Realtime;
use App\Services\Settings\AuthPages;
use App\Services\Sounds\SoundSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\SplFileInfo;

if (file_exists(base_path('routes/api'))) {
    array_filter(File::files(base_path('routes/api')), function (SplFileInfo $file) {
        if ($file->getExtension() === 'php') {
            Route::middleware('api')
                ->group($file->getPathName());
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
            'configuration' => Providers::config()->merge([
                'paystack_public_key' => config('services.paystack.public_key'),
                'flutterwave_public_key' => config('services.flutterwave.public_key'),
            ]),
            'realtime' => Realtime::clientConfig(),
            'sounds' => SoundSettings::clientConfig(),
            'push' => WebPush::clientConfig(),
            'auth' => AuthPages::clientConfig(),
            'accountTypes' => AccountType::options(),
        ],
    ];
});

Route::apiResource('users', UserController::class)
    ->only(['index', 'show']);
Route::apiResource('tracks', TrackController::class)
    ->only(['index', 'show']);
Route::get('tracks/{track}/lyrics', [LyricsController::class, 'show'])
    ->name('tracks.lyrics');
Route::get('tracks/{track}/credits', [TrackCreditsController::class, 'show'])
    ->name('tracks.credits');
Route::get('tracks/{track}/video', [MusicVideoController::class, 'track'])
    ->name('tracks.video');
Route::post('tracks/{track}/plays', [TrackController::class, 'play'])
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->name('tracks.plays');
Route::apiResource('genres', GenreController::class)
    ->only(['index', 'show']);
Route::get('topics', [TopicController::class, 'index'])
    ->name('topics.index');
Route::get('referrals/{code}', [ReferralController::class, 'lookup'])
    ->middleware('throttle:30,1')
    ->name('referrals.lookup');
Route::get('royalty-invites/{token}', [RoyaltyInviteController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('royalty-invites.show');
Route::post('royalty-invites/{token}/accept', [RoyaltyInviteController::class, 'accept'])
    ->middleware(['auth:sanctum', 'throttle:10,1'])
    ->name('royalty-invites.accept');
Route::post('topics', [TopicController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:10,1'])
    ->name('topics.store');
Route::apiResource('artists', ArtistController::class)
    ->only(['index', 'show']);
Route::apiResource('explore', ExploreController::class)
    ->only(['index']);

Route::apiResource('releases', ReleaseController::class)
    ->only(['index', 'show']);
Route::apiResource('releases/{release}/tracks', TrackController::class)
    ->only(['index', 'show'])
    ->names('releases.tracks');

Route::apiResource('projects', ProjectController::class)
    ->only(['index', 'show']);
Route::apiResource('playlist', PlaylistController::class)
    ->only(['index']);

// Subscription plans on offer
Route::get('plans', [PlanController::class, 'index'])
    ->name('plans.index');

// Paystack tells us about payments here; the signature is checked in the controller.
Route::post('payments/paystack/webhook', [PaymentController::class, 'webhook'])
    ->name('payments.webhook');

// Flutterwave sends charges and payout transfers to one URL; both of these take them.
Route::post('payments/flutterwave/webhook', [PaymentController::class, 'flutterwaveWebhook'])
    ->name('payments.webhook.flutterwave');
Route::post('payouts/flutterwave/webhook', [PaymentController::class, 'flutterwaveWebhook'])
    ->name('payouts.webhook.flutterwave');

// Stripe tells us about payout transfers here.
Route::post('payouts/stripe/webhook', [PayoutWebhookController::class, 'stripe'])
    ->name('payouts.webhook.stripe');

// Suno needs a callback for every task; jobs poll for results, so it's ignored.
Route::post('ai/suno/callback', SunoCallbackController::class)
    ->name('ai.suno.callback');

// Studio effects written in Faust
Route::get('studio/faust-effects', [FaustEffectController::class, 'index'])->name('studio.faust-effects');

// The studio's render page, in headless Chrome on the worker: a track to mix and where its mix goes
Route::get('studio/render/tracks/{track}', [MixRenderController::class, 'show'])
    ->middleware('signed')
    ->name('studio.render.show');
Route::post('studio/render/tracks/{track}/mix/{mix}', [MixRenderController::class, 'store'])
    ->middleware('signed')
    ->name('studio.render.store');

// A version of a generated song, from the signed link on its AI job
Route::get('ai/jobs/{job}/versions/{version}/audio', [AiController::class, 'version'])
    ->whereNumber('version')
    ->middleware('signed')
    ->name('ai.jobs.versions.audio');

// An artist's voice sample, from the signed link on the voice
Route::get('artists/{artist}/voice/audio', [ArtistVoiceController::class, 'audio'])
    ->middleware('signed')
    ->name('artists.voice.audio');

// An image an artwork job drew, from the signed link on the job
Route::get('ai/jobs/{job}/artwork/{image}', [AiArtworkController::class, 'image'])
    ->whereNumber('image')
    ->middleware('signed')
    ->name('ai.jobs.artwork.image');

// A finished music video and the stills of its shots, from the signed links on the video
Route::get('ai/videos/{video}/file', [MusicVideoController::class, 'file'])
    ->middleware('signed')
    ->name('ai.videos.file');
Route::get('ai/videos/{video}/shots/{shot}/keyframe', [MusicVideoController::class, 'keyframe'])
    ->middleware('signed')
    ->name('ai.videos.keyframe');
Route::get('ai/videos/{video}/characters/{character}/sheet', [MusicVideoController::class, 'sheet'])
    ->middleware('signed')
    ->name('ai.videos.character');

// A track or stem saved as a file, from a signed link the download endpoints hand out
Route::get('files/download', FileDownloadController::class)
    ->middleware('signed')
    ->name('files.download');

Route::middleware(['auth:sanctum'])
    ->group(function () {
        // Payments for Premium, paid releases, stems and tips
        Route::get('payments/options', [PaymentController::class, 'options'])
            ->name('payments.options');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('payments/{reference}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

        // Paid downloads
        Route::get('tracks/{track}/stems', [TrackController::class, 'stems'])->name('tracks.stems');
        Route::get('tracks/{track}/download', [TrackController::class, 'download'])->name('tracks.download');

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
            )->names("$path.comments");

            // Interaction Routes
            Route::apiResource(
                "$path/{{$param}}/interactions",
                SocialInteractionController::class
            )->only(['store', 'index'])->names("$path.interactions");
        }

        // Ask to manage an artist no account manages yet
        Route::post('artists/{artist}/claims', [ArtistClaimController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('artists.claims.store');

        foreach (['users', 'artists'] as $path) {
            $param = str($path)->singular()->toString();

            // Follows Routes
            Route::apiResource("$path/{{$param}}/follows", FollowController::class)
                ->only(['store', 'index'])
                ->names("$path.follows");
        }
    });
