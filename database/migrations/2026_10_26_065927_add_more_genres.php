<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the genres from database/data/genres.php that aren't there yet.
     */
    public function up(): void
    {
        $now = now();
        $genres = require database_path('data/genres.php');

        $rows = collect($genres)
            ->map(fn(string $title, string $slug) => [
                'slug' => $slug,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        DB::table('genres')->insertOrIgnore($rows);
    }

    /**
     * Remove the added genres that no track or release uses.
     */
    public function down(): void
    {
        $genres = require database_path('data/genres.php');

        DB::table('genres')
            ->whereIn('slug', array_keys($genres))
            ->whereNotExists(fn($q) => $q->selectRaw('1')->from('tracks')->whereColumn('tracks.genre_id', 'genres.id'))
            ->whereNotExists(fn($q) => $q->selectRaw('1')->from('releases')->whereColumn('releases.genre_id', 'genres.id'))
            ->whereNotExists(fn($q) => $q->selectRaw('1')->from('project_tracks')->whereColumn('project_tracks.genre_id', 'genres.id'))
            ->delete();
    }
};
