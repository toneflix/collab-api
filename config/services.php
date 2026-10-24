<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'freesound' => [
        // Changeable for testing against a stand-in API.
        'base_url' => env('FREESOUND_BASE_URL', 'https://freesound.org/apiv2'),
        // Seconds to wait between downloads, to stay under Freesound's limits.
        'download_delay' => (float) env('FREESOUND_DOWNLOAD_DELAY', 2),
    ],

    'realtime' => [
        // none, reverb, pusher, ably or firebase. Admins can change it.
        'driver' => env('REALTIME_DRIVER', 'none'),
    ],

    'firebase' => [
        // The service account JSON, or a path to it.
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'database_url' => env('FIREBASE_DATABASE_URL'),
        'api_key' => env('FIREBASE_API_KEY'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'app_id' => env('FIREBASE_APP_ID'),
    ],

];
