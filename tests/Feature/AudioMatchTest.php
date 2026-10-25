<?php

namespace Tests\Feature;

use App\Jobs\FingerprintTrack;
use App\Models\Artist;
use App\Models\AudioFingerprint;
use App\Models\Genre;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Track;
use App\Models\User;
use App\Services\Audio\AudioMatcher;
use App\Services\Audio\Fingerprinter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioMatchTest extends TestCase
{
    use DatabaseTransactions;

    protected string $dir;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        $this->dir = sys_get_temp_dir() . '/audio-match-' . Str::random(8);
        mkdir($this->dir);

        // Artists come with tracks of their own, so make one before any audio exists.
        $this->artist = Artist::factory()
            ->create();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * Make a 30 second test file: a tune of tones picked by the seed, over
     * quiet noise.
     */
    protected function audio(string $name, int $seed, int $bitrate = 192, float $trim = 0): string
    {
        $path = "{$this->dir}/{$name}.mp3";
        $process = new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "anoisesrc=d=32:c=pink:seed={$seed},volume=0.05",
            '-f', 'lavfi', '-i', "aevalsrc='0.5*sin(2*PI*(220+55*mod(floor(t*{$seed}*0.7)*{$seed},13))*t)':d=32",
            '-filter_complex', 'amix=inputs=2',
            '-ss', (string) $trim, '-t', '30', '-b:a', "{$bitrate}k", $path,
        ]);
        $process->mustRun();

        return $path;
    }

    protected function track(User $owner, string $file): Track
    {
        $name = Str::random(12) . '.mp3';
        Storage::put('public/media/music/tracks/' . $name, file_get_contents($file));

        $genre = Genre::factory()
            ->create();
        $release = Release::factory()
            ->create([
                'draft' => false,
                'genre_id' => $genre->id,
                'user_id' => $owner->id,
                'artist_id' => $this->artist->id,
            ]);

        $track = Track::factory()
            ->create([
                'release_id' => $release->id,
                'genre_id' => $genre->id,
                'audio' => $name,
                'duration' => 30,
            ]);

        FingerprintTrack::dispatchSync($track);

        return $track;
    }

    protected function requireChromaprint(): void
    {
        if (! app(Fingerprinter::class)->available()) {
            $this->markTestSkipped('FFmpeg was built without Chromaprint.');
        }
    }

    public function test_an_exact_copy_of_another_creators_track_is_flagged(): void
    {
        $file = $this->audio('original', 3);
        $original = $this->track(User::factory()->create(), $file);
        $copy = $this->track(User::factory()->create(), $file);

        $flag = Safety::query()
            ->forItem($copy)
            ->where('type', Safety::TYPE_MATCH)
            ->first();

        $this->assertNotNull($flag);
        $this->assertSame($original->id, $flag->data['match_id']);
        $this->assertSame('exact', $flag->data['kind']);
        $this->assertSame(Safety::PENDING, $flag->status);
        $this->assertFalse(Safety::query()->forItem($original)->exists());
    }

    public function test_a_re_encoded_copy_is_flagged(): void
    {
        $this->requireChromaprint();

        $original = $this->track(User::factory()->create(), $this->audio('original', 3));
        $copy = $this->track(User::factory()->create(), $this->audio('copy', 3, 64, 1.5));

        $flag = Safety::query()
            ->forItem($copy)
            ->where('type', Safety::TYPE_MATCH)
            ->first();

        $this->assertNotNull($flag);
        $this->assertSame($original->id, $flag->data['match_id']);
        $this->assertSame('acoustic', $flag->data['kind']);
        $this->assertGreaterThanOrEqual(AudioMatcher::THRESHOLD, $flag->data['score']);
    }

    public function test_different_audio_is_not_flagged(): void
    {
        $this->requireChromaprint();

        $this->track(User::factory()->create(), $this->audio('one', 3));
        $other = $this->track(User::factory()->create(), $this->audio('two', 11));

        $this->assertNotNull(AudioFingerprint::query()->where('model_id', $other->id)->value('codes'));
        $this->assertFalse(Safety::query()->forItem($other)->exists());
    }

    public function test_the_same_owner_uploading_twice_is_not_flagged(): void
    {
        $owner = User::factory()
            ->create();
        $file = $this->audio('mine', 5);

        $this->track($owner, $file);
        $again = $this->track($owner, $file);

        $this->assertFalse(Safety::query()->forItem($again)->exists());
    }

    public function test_flags_show_in_the_moderation_queue(): void
    {
        $file = $this->audio('original', 3);
        $this->track(User::factory()->create(), $file);
        $copy = $this->track(User::factory()->create(), $file);

        $moderator = User::factory()
            ->create();
        $moderator->givePermissionTo(Permission::findOrCreate('manage-content'));
        Sanctum::actingAs($moderator);

        $this->getJson('/api/admin/moderation/queue?source=match')
            ->assertOk()
            ->assertJsonPath('data.0.item.id', $copy->id)
            ->assertJsonPath('data.0.matchesCount', 1)
            ->assertJsonPath('data.0.reports.0.claim.kind', 'exact');
    }
}
