<?php

namespace Tests\Feature\Jobs;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessAudioTest extends TestCase
{
    use WithFaker;

    /**
     * A basic feature test example.
     */
    public function testAudioGetsProcessedSuccessfully(): void
    {
        Storage::fake('default');
        Sanctum::actingAs(
            $user = User::inRandomOrder()->whereHas('releases')->first(),
            ['*']
        );

        $image = UploadedFile::fake()->image('image.jpg', 640, 640);

        $audio = new UploadedFile(
            storage_path('app/public/media/music/tracks/1956696705_558104677.mp3'),
            'audio.mp3',
            'audio/mp3',
            null,
            true
        );

        $response = $this->post("api/creator/releases/{$user->releases[0]->id}/tracks?with=streamUrl", [
            'image' => $image,
            'audio' => $audio,
            'title' => $this->faker->words(4, true),
            'description' => $this->faker->sentence(6, true),
            'privacy' => 'public',
            'genre_id' => (string)Genre::inRandomOrder()->first()->id,
            'artist_id' => (string)Artist::inRandomOrder()->first()->id,
        ]);

        $id = $response->json('data.id');
        $data = $user->releases[0]->tracks()->find($id);

        $response->assertCreated();
        $this->assertTrue($data->meta['processed']);
        $this->assertTrue($data->duration > 0);
    }
}
