<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Faust effects
    |--------------------------------------------------------------------------
    |
    | Studio effects written in Faust (resources/faust). The studio compiles
    | them in the browser; the server reads each one's settings with the
    | Faust compiler (the faust package).
    |
    */
    'faust' => [
        'binary' => env('FAUST_BINARY', 'faust'),
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

    /*
    |--------------------------------------------------------------------------
    | Mixes without a browser
    |--------------------------------------------------------------------------
    |
    | Mixes nobody has the studio open for, like content agent runs, are made
    | by the studio itself in headless Chrome on the worker. Chrome opens the
    | app's render page (`url`, the frontend's /studio/render unless set),
    | which mixes the track and uploads it. `timeout` is in seconds.
    |
    */
    'render' => [
        'chrome' => env('STUDIO_CHROME_BINARY', 'chromium'),
        'url' => env('STUDIO_RENDER_URL'),
        'timeout' => (int) env('STUDIO_RENDER_TIMEOUT', 600),
    ],
];
