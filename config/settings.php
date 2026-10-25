<?php

use App\Enums\PeriodDays;

return [
    /*
    |--------------------------------------------------------------------------
    | Recommendation Expiry
    |--------------------------------------------------------------------------
    |
    | Users will not get anymore recommendation after their last recommendation
    | Until this expiry elapses
    |
    */
    'recommendation_expiry' =>         PeriodDays::DAYS_3,

    /*
    |--------------------------------------------------------------------------
    | Maximum Daily Recommendation
    |--------------------------------------------------------------------------
    |
    | For each category of recommendable content, this will be the maximum
    | number of recommendations that a user can get in a day.
    |
    */
    'max_daily_recommendation' =>      5,

    /*
    |--------------------------------------------------------------------------
    | Deleting Reputation Cost
    |--------------------------------------------------------------------------
    |
    | Deleting own project allows users to earn reputation, but this comes at a
    | cost, this will be how much props a project needs to have earned before
    | the user can gain reoutation from deleting it.
    |
    */
    'delete_reputation_cost' =>         40,

    /*
    |--------------------------------------------------------------------------
    | Reputation
    |--------------------------------------------------------------------------
    |
    | Users earn reputation when certain actions happen on thier account or
    | or with content they created
    |
    */
    'reputation' => [
        'track_likes' =>                0.3,   // [x]
        'track_views' =>                0.3,   // [x]
        'track_saves' =>                0.5,   // [x]
        'track_streams' =>              1.2,   // [x]

        'release_likes' =>              0.3,   // [x]
        'release_views' =>              0.3,   // [x]
        'profile_follows' =>            5,     // [x]

        'first_project' =>              100,   // [x]
        'project_props' =>              5,     // [x]
        'create_project' =>             3,     // [x]
        'delete_project' =>             10,    // [x]
        'publish_project' =>            50,    // [x]
        'create_project_track' =>       3,     // [x]
        'publish_first_project' =>      100,   // [x]
        'collab_request_approved' =>    20,    // [x]
        'collab_invitation_accepted' => 20,    // [x]
        'create_project_track_stem' =>  10,    // [x]
    ],

    /*
    |--------------------------------------------------------------------------
    | Audio Encoding
    |--------------------------------------------------------------------------
    |
    | These will control how audio will be encoded after they are uploaded
    |
    */
    'encoding' => [
        /*
        |--------------------------------------------------------------------------
        | Enable Audio Encoding
        |--------------------------------------------------------------------------
        |
        | If disabled audio will not be encoded after upload
        |
        */
        'enabled' =>             true,

        /*
        |--------------------------------------------------------------------------
        | Lossless Formats
        |--------------------------------------------------------------------------
        |
        | Only audio of these formats will be encoded, others will be ignored
        |
        */
        'formats' => [
            'wav',
            'flac',
            'aiff',
            'alac',
        ],

        /*
        |--------------------------------------------------------------------------
        | Lossy Formats
        |--------------------------------------------------------------------------
        |
        | Audio of these formats is kept as uploaded and only has its length read,
        | since encoding lossy audio again loses quality.
        |
        */
        'keep' => [
            'mp3',
            'aac',
            'm4a',
            'ogg',
            'opus',
        ],

        /*
        |--------------------------------------------------------------------------
        | Lossless Output
        |--------------------------------------------------------------------------
        |
        | Audio will be encoded to this format
        |
        */
        'output' =>                'aac',

        /*
        |--------------------------------------------------------------------------
        | Output Codec
        |--------------------------------------------------------------------------
        |
        | Optional FFmpeg codec for the output format. Leave empty to use the
        | format's default (libfdk_aac for aac). Set to "aac" to use FFmpeg's
        | native AAC encoder when libfdk_aac is not installed.
        |
        */
        'output_codec' =>          env('AUDIO_ENCODING_CODEC'),

        /*
        |--------------------------------------------------------------------------
        | Output Bitrate
        |--------------------------------------------------------------------------
        |
        | Bitrate of encoded files
        |
        */
        'output_bitrate' =>        '256', //kbps

        /*
        |--------------------------------------------------------------------------
        | Progress Logs
        |--------------------------------------------------------------------------
        |
        | If enabled, as audio is being encoded, we will log the progress to the db
        | to allow users follow up.
        |
        */
        'enable_progress_logs' =>  true
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | message_delay: minutes to wait after a new message before emailing or
    | pushing it, so people reading along aren't notified and a burst of
    | messages makes one notification.
    |
    */
    'notifications' => [
        'message_delay' => (int) env('NOTIFY_MESSAGE_DELAY', 5),
    ],
];
