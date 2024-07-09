<?php

use App\Enums\PeriodDays;

return [
    'recommendation_expiry' => PeriodDays::DAYS_3,
    'max_daily_recommendation' => 5,

    'reputation' => [
        'track_views' => 03,
        'track_saves' => 0.5,
        'track_streams' => 1.2,

        'release_views' => 0.3,
        'profile_follows' => 5,

        'join_project' => 20,
        'first_project' => 100,
        'project_props' => 5,
        'create_project' => 3,
        'publish_project' => 50,
        'create_project_track' => 3,
        'publish_first_project' => 100,
        'project_collab_accepted' => 20,
        'create_project_track_stem' => 10,
    ],
];
