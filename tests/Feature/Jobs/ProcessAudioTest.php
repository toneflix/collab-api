<?php

namespace Tests\Feature\Jobs;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProcessAudioTest extends TestCase
{
    use DatabaseTransactions;
    use WithFaker;

    /**
     * A basic feature test example.
     */
    public function test_audio_gets_processed_successfully(): void
    {
        Storage::fake('default');

        // libfdk_aac is not always installed, so use FFmpeg's native AAC encoder here
        config(['settings.encoding.output' => 'aac', 'settings.encoding.output_codec' => 'aac']);
        $genre = Genre::factory()
            ->create();
        $artist = Artist::factory()
            ->create();
        $user = User::factory()
            ->create();
        Release::factory()
            ->create(['user_id' => $user->id, 'genre_id' => $genre->id, 'artist_id' => $artist->id]);

        Sanctum::actingAs($user, ['*']);

        $image = UploadedFile::fake()
            ->image('image.jpg', 640, 640);

        // Work on a copy so the upload does not move the fixture away
        $path = tempnam(sys_get_temp_dir(), 'audio') . '.mp3';
        copy(base_path('tests/fixtures/audio.mp3'), $path);

        $audio = new UploadedFile(
            $path,
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
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
        ]);

        $id = $response->json('data.id');
        $data = $user->releases[0]
            ->tracks()
            ->find($id);

        $response->assertCreated();
        $this->assertTrue($data->meta['processed']);
        $this->assertTrue($data->duration > 0);
    }
}
