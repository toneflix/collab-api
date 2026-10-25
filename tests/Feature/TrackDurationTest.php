<?php

namespace Tests\Feature;

use App\Jobs\MeasureDuration;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Audio\TrackAudio;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

class TrackDurationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $owner;

    protected Release $release;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->owner = User::factory()
            ->create();
        $this->release = Release::query()
            ->forceCreate([
                'user_id' => $this->owner->id,
                'title' => 'Album',
                'slug' => 'album-' . uniqid(),
                'draft' => false,
            ]);
    }

    public function test_release_duration_is_the_sum_of_its_tracks(): void
    {
        $this->track(['duration' => 183]);
        $this->track(['duration' => 92]);

        $id = $this->release->id;
        $release = Release::query()
            ->find($id);

        $this->assertSame(2, $release->tracks_count);
        $this->assertSame(275, (int) $release->tracks_sum_duration);
    }

    public function test_measuring_stores_the_audio_length(): void
    {
        $media = new Media;
        $name = 'tone-' . uniqid() . '.mp3';
        $path = $media->mediaInfo('music_tracks', $name)['path'];
        $local = sys_get_temp_dir() . '/' . $name;

        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', $local]))
            ->mustRun();
        $media->disk->put($path, file_get_contents($local));
        @unlink($local);

        $track = $this->track(['audio' => $name]);

        $seconds = app(TrackAudio::class)
            ->measure($track);

        $this->assertSame(3, $seconds);
        $this->assertSame(3, (int) $track->fresh()->duration);

        $media->disk->delete($path);
    }

    public function test_command_queues_tracks_without_a_length(): void
    {
        $this->track(['audio' => 'a.mp3', 'duration' => 0]);
        $this->track(['audio' => 'b.mp3', 'duration' => 120]);

        $this
            ->artisan('tracks:durations')
            ->assertSuccessful();

        Queue::assertPushed(MeasureDuration::class, fn(MeasureDuration $job) => $job->track->audio === 'a.mp3');
        Queue::assertNotPushed(MeasureDuration::class, fn(MeasureDuration $job) => $job->track->audio === 'b.mp3');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function track(array $attributes = []): Track
    {
        $track = new Track;
        $track->forceFill([
            'release_id' => $this->release->id,
            'title' => 'Song',
            'slug' => 'song-' . uniqid(),
            ...$attributes,
        ]);
        $track->saveQuietly();

        return $track;
    }
}
