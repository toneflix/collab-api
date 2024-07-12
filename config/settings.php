<?php

use App\Enums\PeriodDays;

return [
    'recommendation_expiry' =>    PeriodDays::DAYS_3,
    'max_daily_recommendation' => 5,
    'delete_reputation_cost' =>   40,  // If reputation is earned from deleting, this is how much it will cost to allow earning

    'reputation' => [
        'track_likes' =>               0.3,    // [x]
        'track_views' =>               0.3,    // [x]
        'track_saves' =>               0.5,    // [x]
        'track_streams' =>             1.2,    // [x]

        'release_likes' =>             0.3,    // [x]
        'release_views' =>             0.3,    // [x]
        'profile_follows' =>           5,      // [x]

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
    'lossless_formats' => [
        'mp3',
        'wav',
        'flac',
        'aiff',
        'alac',
    ],
    'lossless_output' =>         'aac',
    'lossless_output_bitrate' => '256', //kbps
];
