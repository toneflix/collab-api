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

    'sendpulse' => [
        'id' => env('SENDPULSE_API_ID'),
        'secret' => env('SENDPULSE_API_SECRET'),
        'url' => env('SENDPULSE_API_URL', 'https://api.sendpulse.com'),
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
        'client_id' => env('FREESOUND_CLIENT_ID'),
        'client_secret' => env('FREESOUND_CLIENT_SECRET'),
        // Changeable for testing against a stand-in API.
        'base_url' => env('FREESOUND_BASE_URL', 'https://freesound.org/apiv2'),
        // Seconds to wait between downloads, to stay under Freesound's limits.
        'download_delay' => (float) env('FREESOUND_DOWNLOAD_DELAY', 2),
    ],

    'datamuse' => [
        'key' => env('DATAMUSE_API_KEY'),
        'base_url' => env('DATAMUSE_BASE_URL', 'https://api.datamuse.com'),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
    ],

    // Live rates for charging in a payment region's currency: exchangerate-api or frankfurter
    'exchange_rates' => [
        'provider' => env('EXCHANGE_RATE_PROVIDER', 'exchangerate-api'),
        'key' => env('EXCHANGERATE_API_KEY'),
    ],

    // The Toneflix payment relay; once set, gateway webhooks must carry its signature.
    'relay' => [
        'secret' => env('RELAY_SECRET'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        // Used for Connect payouts instead of the secret key when set.
        'connect_secret_key' => env('STRIPE_CONNECT_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'realtime' => [
        // none, reverb, pusher, ably or firebase. Admins can change it.
        'driver' => env('REALTIME_DRIVER', 'none'),
    ],

    'firebase' => [
        // What browsers use to connect; the server's service account is under broadcasting.connections.firebase.
        'database_url' => env('FIREBASE_DATABASE_URL'),
        'api_key' => env('FIREBASE_API_KEY'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'app_id' => env('FIREBASE_APP_ID'),
        // The service account JSON, or a path to it, that sends push to the mobile apps.
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

];
