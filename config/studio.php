<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Faust effects
    |--------------------------------------------------------------------------
    |
    | Studio effects written in Faust (resources/faust). The studio compiles
    | them in the browser; the server builds each into a LADSPA plugin with
    | faust2ladspa and runs it through FFmpeg's ladspa filter. Needs the
    | faust, ladspa-sdk and g++ packages.
    |
    */
    'faust' => [
        'binary' => env('FAUST_BINARY', 'faust'),
        'ladspa' => env('FAUST2LADSPA_BINARY', 'faust2ladspa'),
        'plugins' => env('FAUST_PLUGIN_PATH', storage_path('app/faust')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Studio mixes
    |--------------------------------------------------------------------------
    |
    | Largest file, in MB, the studio may upload for a track's mix. The studio
    | sends 16-bit FLAC, about 5 MB per minute. PHP's upload_max_filesize and
    | post_max_size must allow it too.
    |
    */
    'mix_max_mb' => (int) env('STUDIO_MIX_MAX_MB', 100),
];
