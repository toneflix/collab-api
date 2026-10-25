<?php

namespace Tests\Feature;

use App\Enums\MusicalKey;
use App\Jobs\FingerprintTrack;
use App\Jobs\ProcessAudio;
use App\Jobs\TagAudio;
use App\Jobs\TranscribeTrack;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Audio\Analysis\AudioAnalyzer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioTagsTest extends TestCase
{
    use DatabaseTransactions;

    protected string $dir;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Bus::fake([TagAudio::class, TranscribeTrack::class, FingerprintTrack::class, ProcessAudio::class]);

        $this->dir = sys_get_temp_dir().'/audio-tags-'.Str::random(8);
        mkdir($this->dir);
        $this->owner = User::factory()->create();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        AiSettings::flush();

        parent::tearDown();
    }

    /**
     * Make a 30 second song: a kick on every beat under an A minor, D minor,
     * E major, A minor chord loop.
     */
    protected function song(int $bpm): string
    {
        $note = fn (int $midi) => 440 * 2 ** (($midi - 69) / 12);
        $chord = fn (int ...$notes) => implode('+', array_map(fn ($n) => 'sin(2*PI*'.$note($n).'*t)', $notes));
        $chords = 'if(lt(mod(t,8),2),'.$chord(57, 60, 64).',if(lt(mod(t,8),4),'.$chord(62, 65, 69)
            .',if(lt(mod(t,8),6),'.$chord(64, 68, 71).','.$chord(57, 60, 64).')))';
        $beat = 60 / $bpm;

        $path = "{$this->dir}/{$bpm}.mp3";
        (new Process([
            'ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
            '-i', "aevalsrc='0.6*sin(2*PI*55*t)*exp(-25*mod(t,{$beat}))+0.1*({$chords})':s=22050:d=30",
            '-ac', '1', '-b:a', '96k', $path,
        ]))->mustRun();

        return $path;
    }

    protected function release(array $track = [], bool $draft = false): Track
    {
        $genre = Genre::factory()->create();
        $release = Release::factory()->create([
            'draft' => $draft,
            'price' => 0,
            'genre_id' => $genre->id,
            'user_id' => $this->owner->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);

        return Track::factory()->create(['release_id' => $release->id, 'genre_id' => $genre->id, 'audio' => null, ...$track]);
    }

    protected function upload(string $file): string
    {
        $name = Str::random(12).'.mp3';
        Storage::put('public/media/music/tracks/'.$name, file_get_contents($file));

        return $name;
    }

    protected function projectTrack(): ProjectTrack
    {
        $project = Project::query()->forceCreate(['user_id' => $this->owner->id, 'title' => 'Tags test', 'privacy' => 'private']);

        return $project->tracks()->forceCreate(['title' => 'Night Run', 'privacy' => 'private']);
    }

    protected function tag(Track|ProjectTrack $owner, bool $inherit = false): void
    {
        app()->call([new TagAudio($owner, $inherit), 'handle']);
    }

    /**
     * A faked job never runs, so its unique lock has to be let go by hand.
     */
    protected function unlock(Track|ProjectTrack $owner): void
    {
        (new UniqueLock(Cache::driver()))->release(new TagAudio($owner));
    }

    public function test_the_analyzer_finds_the_tempo_and_key(): void
    {
        foreach ([128, 95] as $bpm) {
            $tags = app(AudioAnalyzer::class)->analyze($this->song($bpm), 30);

            $this->assertEqualsWithDelta($bpm, $tags->bpm, 1, "Tempo of the {$bpm} BPM song");
            $this->assertSame(MusicalKey::A_MINOR, $tags->key);
        }
    }

    public function test_keys_have_short_names_camelot_codes_and_mixable_neighbours(): void
    {
        $this->assertSame('8A', MusicalKey::A_MINOR->camelot());
        $this->assertSame('8B', MusicalKey::C_MAJOR->camelot());
        $this->assertSame('2B', MusicalKey::FS_MAJOR->camelot());
        $this->assertSame('F#m', MusicalKey::FS_MINOR->short());
        $this->assertSame(MusicalKey::A_MINOR, MusicalKey::parse('8a'));
        $this->assertSame(MusicalKey::BB_MAJOR, MusicalKey::parse('Bb'));
        $this->assertNull(MusicalKey::parse('H minor'));
        $this->assertSame(['Am', 'C', 'Dm', 'Em'], array_map(fn ($key) => $key->short(), MusicalKey::A_MINOR->compatible()));
    }

    public function test_uploaded_tracks_are_tagged_and_show_their_tags(): void
    {
        $track = $this->release(['audio' => $this->upload($this->song(128)), 'duration' => 30]);

        Bus::assertDispatched(TagAudio::class, fn (TagAudio $job) => $job->owner->is($track) && ! $job->inherit);
        $this->tag($track);

        $track->refresh();
        $this->assertEqualsWithDelta(128, $track->bpm, 1);
        $this->assertSame(MusicalKey::A_MINOR, $track->musical_key);
        $this->assertSame(Track::TAGS_DETECTED, $track->tags_source);

        $this->getJson("/api/tracks/{$track->id}")
            ->assertOk()
            ->assertJsonPath('data.tags.key', 'A minor')
            ->assertJsonPath('data.tags.keyShort', 'Am')
            ->assertJsonPath('data.tags.camelot', '8A');
    }

    public function test_detection_can_be_turned_off(): void
    {
        AiSettings::update(['ai_tags_auto' => false]);
        $track = $this->release(['audio' => $this->upload($this->song(128))]);

        $this->tag($track);

        $this->assertNull($track->fresh()->tagged_at);
    }

    public function test_creators_correct_tags_and_detection_keeps_them(): void
    {
        $track = $this->release(['audio' => $this->upload($this->song(128))]);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/creator/tracks/{$track->id}/tags", ['bpm' => 64, 'key' => 'C major'])
            ->assertAccepted()
            ->assertJsonPath('data.camelot', '8B');

        $this->tag($track);

        $track->refresh();
        $this->assertSame(64.0, $track->bpm);
        $this->assertSame(MusicalKey::C_MAJOR, $track->musical_key);
        $this->assertSame(Track::TAGS_MANUAL, $track->tags_source);

        $this->putJson("/api/creator/tracks/{$track->id}/tags", ['key' => 'H minor'])->assertUnprocessable();

        Bus::fake([TagAudio::class]);
        $this->unlock($track);
        $this->putJson("/api/creator/tracks/{$track->id}/tags", ['bpm' => null, 'key' => null])->assertAccepted();
        $this->assertNull($track->fresh()->tags_source);
        Bus::assertDispatched(TagAudio::class, fn (TagAudio $job) => $job->owner->is($track));

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/creator/tracks/{$track->id}/tags", ['bpm' => 100])->assertNotFound();
    }

    public function test_studio_mixes_are_tagged_and_pass_their_tags_on(): void
    {
        $projectTrack = $this->projectTrack();
        $projectTrack->forceFill(['preview' => $this->upload($this->song(95))])->save();

        Bus::assertDispatched(TagAudio::class, fn (TagAudio $job) => $job->owner->is($projectTrack));

        $draft = $this->release(['project_track_id' => $projectTrack->id], draft: true);
        $published = $this->release(['project_track_id' => $projectTrack->id]);
        $published->forceFill(['bpm' => 90, 'tags_source' => Track::TAGS_DETECTED])->saveQuietly();

        $this->tag($projectTrack);

        $this->assertEqualsWithDelta(95, $projectTrack->fresh()->bpm, 1);
        $this->assertEqualsWithDelta(95, $draft->fresh()->bpm, 1);
        $this->assertSame(MusicalKey::A_MINOR, $draft->fresh()->musical_key);
        $this->assertSame(90.0, $published->fresh()->bpm);

        $released = $this->release(['project_track_id' => $projectTrack->id]);
        Bus::assertDispatched(TagAudio::class, fn (TagAudio $job) => $job->owner->is($released) && $job->inherit);
        $this->tag($released, inherit: true);

        $this->assertEqualsWithDelta(95, $released->fresh()->bpm, 1);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/creator/projects/{$projectTrack->project_id}/tracks/{$projectTrack->id}/tags", ['bpm' => 96, 'key' => 'A minor'])
            ->assertAccepted();
        $this->assertSame(96.0, $draft->fresh()->bpm);
        $this->getJson("/api/creator/projects/{$projectTrack->project_id}/tracks/{$projectTrack->id}")
            ->assertJsonPath('data.tags.source', Track::TAGS_MANUAL);
    }

    public function test_tracks_can_be_filtered_by_tempo_and_key(): void
    {
        $fast = $this->release(['title' => 'Fast one']);
        $slow = $this->release(['title' => 'Slow one']);
        $fast->forceFill(['bpm' => 140, 'musical_key' => MusicalKey::A_MINOR, 'tags_source' => Track::TAGS_DETECTED])->saveQuietly();
        $slow->forceFill(['bpm' => 82, 'musical_key' => MusicalKey::C_MAJOR, 'tags_source' => Track::TAGS_DETECTED])->saveQuietly();

        $ids = fn (string $query) => collect($this->getJson("/api/tracks?limit=100&{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$fast->id], array_intersect($ids('bpm_min=120&bpm_max=150'), [$fast->id, $slow->id]));
        $this->assertEqualsCanonicalizing([$slow->id], array_intersect($ids('key=8B'), [$fast->id, $slow->id]));
        $this->assertEqualsCanonicalizing([$fast->id, $slow->id], array_intersect($ids('key=Am&compatible=1'), [$fast->id, $slow->id]));
        $this->assertSame([], array_intersect($ids('key=Am&bpm_max=100'), [$fast->id, $slow->id]));
    }

    public function test_a_command_queues_audio_without_tags(): void
    {
        $track = $this->release(['audio' => $this->upload($this->song(128))]);
        Bus::fake([TagAudio::class]);
        $this->unlock($track);

        $this->artisan('tags:detect')->assertSuccessful();

        Bus::assertDispatched(TagAudio::class, fn (TagAudio $job) => $job->owner->is($track));
    }
}
