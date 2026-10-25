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
    | Printed stems
    |--------------------------------------------------------------------------
    |
    | Largest WAV, in MB, the studio may upload for a stem recorded through
    | plugins FFmpeg can't run. About 10 MB per minute. PHP's
    | upload_max_filesize and post_max_size must allow it too.
    |
    */
    'print_max_mb' => (int) env('STUDIO_PRINT_MAX_MB', 100),
];
