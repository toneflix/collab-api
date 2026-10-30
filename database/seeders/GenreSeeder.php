<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenreSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        Genre::truncate();
        Schema::enableForeignKeyConstraints();
        Genre::insert([
            [
                'id' => 1,
                'slug' => 'pop',
                'title' => 'Pop',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00015',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 2,
                'slug' => 'rock',
                'title' => 'Rock',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00016',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 3,
                'slug' => 'hip-hop',
                'title' => 'Hip Hop',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00017',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 4,
                'slug' => 'jazz',
                'title' => 'Jazz',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00018',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 5,
                'slug' => 'country',
                'title' => 'Country',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00019',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 6,
                'slug' => 'blues',
                'title' => 'Blues',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00020',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 7,
                'slug' => 'reggae',
                'title' => 'Reggae',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00021',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 8,
                'slug' => 'electronic',
                'title' => 'Electronic',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00022',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 9,
                'slug' => 'classical',
                'title' => 'Classical',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00023',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 10,
                'slug' => 'metal',
                'title' => 'Metal',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00024',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 11,
                'slug' => 'folk',
                'title' => 'Folk',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00025',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 12,
                'slug' => 'indie',
                'title' => 'Indie',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00026',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 13,
                'slug' => 'r&b',
                'title' => 'R&B',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00027',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 14,
                'slug' => 'punk',
                'title' => 'Punk',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00028',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 15,
                'slug' => 'soul',
                'title' => 'Soul',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00029',
                'updated_at' => now(),
                'created_at' => now(),
            ],
            [
                'id' => 16,
                'slug' => 'dance',
                'title' => 'Dance',
                'image' => 'https://lorem.toneflix.com.ng/images/image/00030',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        ]);

        // Inserting explicit ids doesn't move PostgreSQL's id sequence, so move it past them.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('genres', 'id'), (SELECT MAX(id) FROM genres))");
        }

        $now = now();
        $more = collect(require database_path('data/genres.php'))
            ->map(fn(string $title, string $slug) => [
                'slug' => $slug,
                'title' => $title,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        Genre::insertOrIgnore($more);

        foreach (require database_path('data/genre-prompts.php') as $slug => $prompt) {
            Genre::query()
                ->where('slug', $slug)
                ->update(['prompt' => $prompt]);
        }
    }
}
