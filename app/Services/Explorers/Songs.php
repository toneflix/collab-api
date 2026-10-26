<?php

namespace App\Services\Explorers;

use App\Http\Controllers\PublicSongController;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Explore sections for published Composer songs: `new_songs` and `top_songs`
 * (most forked), each asking for that many songs.
 */
class Songs
{
    public function __construct(
        public Request $request,
        public Collection $instance
    ) {}

    public function __destruct()
    {
        $sections = [
            'new_songs' => 'latest',
            'top_songs' => 'forks',
        ];

        foreach ($sections as $key => $sort) {
            if (! $this->request->has($key)) {
                continue;
            }

            $request = Request::createFrom($this->request)->merge([
                'sort' => $sort,
                'limit' => $this->request->input($key, 18),
            ]);

            $resource = (new PublicSongController)->index($request);

            $this->instance->put($key, Section::from($resource));
        }
    }
}
