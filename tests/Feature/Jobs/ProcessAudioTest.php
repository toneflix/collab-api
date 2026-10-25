<?php

namespace Tests\Feature\Jobs;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Track;
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

    public function test_mp3_uploads_are_kept_as_uploaded(): void
    {
        $track = $this->upload(base_path('tests/fixtures/audio.mp3'), 'mp3');

        $this->assertTrue($track->meta['processed']);
        $this->assertSame('mp3', $track->meta['format']);
        $this->assertStringEndsWith('.mp3', $track->audio);
        $this->assertTrue($track->duration > 0);
    }

    public function test_lossless_uploads_are_encoded(): void
    {
        $wav = tempnam(sys_get_temp_dir(), 'audio').'.wav';
        exec('ffmpeg -loglevel error -y -i '.escapeshellarg(base_path('tests/fixtures/audio.mp3')).' '.escapeshellarg($wav));

        $track = $this->upload($wav, 'wav');

        $this->assertTrue($track->meta['processed']);
        $this->assertSame('aac', $track->meta['format']);
        $this->assertTrue($track->duration > 0);
    }

    protected function upload(string $source, string $ext): Track
    {
        Storage::fake('default');

        // libfdk_aac is not always installed, so use FFmpeg's native AAC encoder here
        config(['settings.encoding.output' => 'aac', 'settings.encoding.output_codec' => 'aac']);
        $genre = Genre::factory()->create();
        $artist = Artist::factory()->create();
        $user = User::factory()->create();
        $release = Release::factory()->create(['user_id' => $user->id, 'genre_id' => $genre->id, 'artist_id' => $artist->id]);

        Sanctum::actingAs($user, ['*']);

        // Work on a copy so the upload does not move the source away
        $path = tempnam(sys_get_temp_dir(), 'audio').".{$ext}";
        copy($source, $path);

        $response = $this->post("api/creator/releases/{$release->id}/tracks", [
            'image' => UploadedFile::fake()->image('image.jpg', 640, 640),
            'audio' => new UploadedFile($path, "audio.{$ext}", $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav', null, true),
            'title' => $this->faker->words(4, true),
            'description' => $this->faker->sentence(6, true),
            'privacy' => 'public',
            'genre_id' => (string) $genre->id,
            'artist_id' => (string) $artist->id,
        ]);

        $response->assertCreated();

        return Track::query()->findOrFail($response->json('data.id'));
    }
}
