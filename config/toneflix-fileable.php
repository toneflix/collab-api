<?php

// Placeholders shipped in public/defaults. The app serves them itself,
// so they work whichever disk (local or S3) holds the uploads.
$defaults = rtrim((string) env('ASSET_URL', env('APP_URL', 'http://localhost')), '/').'/defaults/';

return [
    'collections' => [
        'avatar' => [
            'size' => [400, 400],
            'path' => 'avatars/',
            'default' => $defaults.'avatar.svg',
        ],
        'artwork' => [
            'size' => [520, 520],
            'path' => 'artwork/',
            'default' => $defaults.'artwork.svg',
        ],
        'music_tracks' => [
            'path' => 'media/music/tracks/',
            'default' => 'default.mp3',
        ],
        'stem_files' => [
            'path' => 'media/music/stem/',
            'default' => 'default.mp3',
        ],
        'voice_notes' => [
            'path' => 'media/voice-notes/',
        ],
        'banner' => [
            'size' => [1200, 600],
            'path' => 'media/banners/',
            'default' => $defaults.'banner.svg',
        ],
        'default' => [
            'path' => 'media/default/',
            'default' => $defaults.'image.svg',
        ],
        'logo' => [
            'size' => [200, 200],
            'path' => 'media/logos/',
            'default' => $defaults.'image.svg',
        ],
        'private' => [
            'files' => [
                'path' => 'files/',
                'secure' => false,
            ],
            'images' => [
                'path' => 'files/images/',
                'default' => $defaults.'image.svg',
                'secure' => true,
            ],
            'videos' => [
                'path' => 'files/videos/',
                'secure' => true,
            ],
        ],
    ],
    'image_sizes' => [
        'xs' => '431',
        'sm' => '431',
        'md' => '694',
        'lg' => '720',
        'xl' => '1080',
        'xs-square' => '431x431',
        'sm-square' => '431x431',
        'md-square' => '694x694',
        'lg-square' => '720x720',
        'xl-square' => '1080x1080',
    ],
    'file_route_secure_middleware' => ['secure-asset'],
    // On remote disks like S3, sign file links so private buckets work
    'temporary_urls' => (bool) env('FILEABLE_TEMPORARY_URLS', true),
    'temporary_url_ttl' => (int) env('FILEABLE_TEMPORARY_URL_TTL', 60),
    'responsive_image_route' => 'media/images/responsive/{file}/{size}',
    'file_route_secure' => 'secure/media/files/{file}',
    'file_route_open' => 'media/files/{file}',
    'symlinks' => [
        public_path('artwork') => storage_path('app/public/artwork'),
        public_path('avatars') => storage_path('app/public/avatars'),
        public_path('media') => storage_path('app/public/media'),
    ],
];
