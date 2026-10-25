<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Jobs\FingerprintTrack;
use App\Jobs\ProcessAudio;
use App\Jobs\TranscribeTrack;
use App\Models\AiJob;
use App\Models\Artist;
use App\Models\Genre;
use App\Models\Lyric;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Safety;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Lyrics\Lyrics;
use App\Services\Lyrics\LyricsCheck;
use App\Services\Studio\StemFiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LyricsTest extends TestCase
{
    use DatabaseTransactions;

    protected const API = 'https://replicate.test/v1';

    /**
     * Segments as openai/whisper on Replicate returns them; the last one is
     * the silence Whisper sometimes fills with made up words.
     */
    protected const SEGMENTS = [
        ['id' => 0, 'start' => 1.0, 'end' => 4.5, 'text' => ' Sun is rising over Lagos', 'no_speech_prob' => 0.01, 'avg_logprob' => -0.2],
        ['id' => 1, 'start' => 4.5, 'end' => 8.25, 'text' => ' We dey shine, we dey shine', 'no_speech_prob' => 0.02, 'avg_logprob' => -0.3],
        ['id' => 2, 'start' => 20.0, 'end' => 24.0, 'text' => ' Thank you for watching', 'no_speech_prob' => 0.92, 'avg_logprob' => -1.4],
    ];

    protected User $owner;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Sleep::fake();
        Bus::fake([TranscribeTrack::class, FingerprintTrack::class, ProcessAudio::class]);
        config(['ai.providers.replicate.key' => 'r8_test', 'ai.providers.replicate.url' => self::API]);

        AiSettings::update(['ai_enabled' => true, 'ai_lyrics_credits' => 2, 'ai_lyrics_auto' => true]);

        $this->owner = $this->member(10);
        $this->project = Project::query()->forceCreate(['user_id' => $this->owner->id, 'title' => 'Lyrics test', 'privacy' => 'private']);
        $this->track = $this->project->tracks()->forceCreate(['title' => 'Lagos Morning', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        AiSettings::flush();

        parent::tearDown();
    }

    protected function member(int $credits): User
    {
        $plan = Plan::create([
            'slug' => 'lyrics-' . uniqid(), 'name' => 'AI', 'price' => 1000, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => true, Feature::AI_CREDITS->value => $credits],
        ]);

        $user = User::factory()->create();
        $user->subscriptions()->create([
            'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 1000,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
        ]);

        return $user->fresh();
    }

    protected function url(string $path = 'lyrics'): string
    {
        return "/api/creator/projects/{$this->project->id}/tracks/{$this->track->id}/{$path}";
    }

    /**
     * Replicate answering for Whisper, a community model that runs by version.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    protected function fakeWhisper(array $segments = self::SEGMENTS): void
    {
        Http::fake([
            self::API . '/files' => Http::response(['urls' => ['get' => self::API . '/files/f1']]),
            self::API . '/models/openai/whisper/predictions' => Http::response(['detail' => 'version required'], 404),
            self::API . '/models/openai/whisper' => Http::response(['latest_version' => ['id' => 'v-whisper']]),
            self::API . '/predictions' => Http::response(['id' => 'p-lyrics', 'status' => 'starting']),
            self::API . '/predictions/p-lyrics' => Http::response(['id' => 'p-lyrics', 'status' => 'succeeded', 'output' => [
                'segments' => $segments,
                'transcription' => implode('', array_column($segments, 'text')),
                'detected_language' => 'english',
            ]]),
        ]);
    }

    protected function transcribe(Track $track): void
    {
        app()->call([new TranscribeTrack($track), 'handle']);
    }

    protected function stem(array $meta = []): int
    {
        $file = tempnam(sys_get_temp_dir(), 'test-');
        file_put_contents($file, 'ID3-vocals');
        $stem = app(StemFiles::class)->addStem($this->track, $this->owner, $file, 'Lead vocals', $meta);
        @unlink($file);

        return $stem->id;
    }

    protected function release(User $owner, array $track = []): Track
    {
        $genre = Genre::factory()->create();
        $release = Release::factory()->create([
            'draft' => false,
            'price' => 0,
            'genre_id' => $genre->id,
            'user_id' => $owner->id,
            'artist_id' => Artist::factory()->create()->id,
        ]);

        return Track::factory()->create(['release_id' => $release->id, 'genre_id' => $genre->id, 'audio' => null, ...$track]);
    }

    public function test_a_stem_is_transcribed_into_the_lyric_sheet_at_its_place_on_the_track(): void
    {
        $this->fakeWhisper();
        $stem = $this->stem(['start' => 10]);
        Sanctum::actingAs($this->owner);

        $id = $this->postJson($this->url("stems/{$stem}/lyrics"))
            ->assertCreated()
            ->assertJsonPath('data.kind', 'lyrics')
            ->assertJsonPath('data.credits', 2)
            ->json('data.id');

        $job = AiJob::findOrFail($id);
        $this->assertSame(AiJob::SUCCEEDED, $job->status);
        $this->assertSame(2, $job->output['lines']);
        $this->assertSame(8, Credits::for($this->owner)->total());

        $this->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.source', Lyric::TRANSCRIBED)
            ->assertJsonPath('data.language', 'english')
            ->assertJsonPath('data.synced', true)
            ->assertJsonPath('data.lines', [
                ['start' => 11, 'end' => 14.5, 'text' => 'Sun is rising over Lagos'],
                ['start' => 14.5, 'end' => 18.25, 'text' => 'We dey shine, we dey shine'],
            ]);

        Http::assertSent(fn(Request $request) => $request->url() === self::API . '/predictions'
            && $request['version'] === 'v-whisper'
            && $request['input']['audio'] === self::API . '/files/f1'
            && $request['input']['translate'] === false);
    }

    public function test_words_in_trimmed_off_audio_are_left_out(): void
    {
        $this->fakeWhisper();
        $stem = $this->stem(['start' => 2, 'cuein' => 4.5]);
        Sanctum::actingAs($this->owner);

        $this->postJson($this->url("stems/{$stem}/lyrics"))->assertCreated();

        $this->assertSame(
            [['start' => 2, 'end' => 5.75, 'text' => 'We dey shine, we dey shine']],
            app(Lyrics::class)->for($this->track)->lines
        );
    }

    public function test_collaborators_edit_the_lyric_sheet_and_released_tracks_follow_it(): void
    {
        $released = $this->release($this->owner, ['project_track_id' => $this->track->id]);
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lines' => [
            ['start' => 0.5, 'end' => 3, 'text' => '  First   line '],
            ['text' => 'Second line, not synced yet'],
            ['text' => ''],
        ]])
            ->assertAccepted()
            ->assertJsonPath('data.source', Lyric::WRITTEN)
            ->assertJsonPath('data.lines', [
                ['start' => 0.5, 'end' => 3, 'text' => 'First line'],
                ['start' => null, 'end' => null, 'text' => 'Second line, not synced yet'],
            ]);

        $this->getJson("/api/tracks/{$released->id}/lyrics")
            ->assertOk()
            ->assertJsonPath('data.lines.0.text', 'First line');

        Sanctum::actingAs(User::factory()->create());
        $this->putJson($this->url(), ['lines' => [['text' => 'Not mine']]])->assertForbidden();
    }

    public function test_clearing_the_sheet_removes_the_lyrics(): void
    {
        $released = $this->release($this->owner, ['project_track_id' => $this->track->id]);
        Sanctum::actingAs($this->owner);

        $this->putJson($this->url(), ['lines' => [['text' => 'Shit happens']]])->assertAccepted();
        $this->assertTrue($released->fresh()->explicit);

        $this->putJson($this->url(), ['lines' => []])->assertAccepted()->assertJsonPath('data', null);

        $this->assertNull(app(Lyrics::class)->for($released));
        $this->assertFalse($released->fresh()->explicit);
    }

    public function test_released_tracks_are_transcribed_and_marked_explicit(): void
    {
        $this->fakeWhisper([
            ['start' => 0, 'end' => 3, 'text' => ' Fuckin\' with the vibe tonight', 'no_speech_prob' => 0.01, 'avg_logprob' => -0.2],
        ]);
        $name = Str::random(12) . '.mp3';
        Storage::put('public/media/music/tracks/' . $name, 'ID3-song');
        $track = $this->release(User::factory()->create(), ['audio' => $name]);

        Bus::assertDispatched(TranscribeTrack::class, fn(TranscribeTrack $job) => $job->track->is($track));
        $this->transcribe($track);

        $lyric = app(Lyrics::class)->for($track);
        $this->assertSame(Lyric::TRANSCRIBED, $lyric->source);
        $this->assertSame(['fuckin'], $lyric->flagged['explicit']);
        $this->assertTrue($track->fresh()->explicit);

        $this->getJson("/api/tracks/{$track->id}?with=artist")->assertJsonPath('data.explicit', true);
    }

    public function test_released_tracks_keep_lyrics_their_creator_wrote_and_skip_when_turned_off(): void
    {
        Http::fake();
        $name = Str::random(12) . '.mp3';
        Storage::put('public/media/music/tracks/' . $name, 'ID3-song');
        $track = $this->release($owner = User::factory()->create(), ['audio' => $name]);

        Sanctum::actingAs($owner);
        $this->putJson("/api/creator/tracks/{$track->id}/lyrics", ['lines' => [['text' => 'My own words']]])->assertAccepted();

        $this->transcribe($track);
        $this->assertSame('My own words', app(Lyrics::class)->for($track)->text);

        $other = $this->release($owner, ['audio' => $name]);
        AiSettings::update(['ai_lyrics_auto' => false]);
        $this->transcribe($other);

        $this->assertNull(app(Lyrics::class)->for($other));
        Http::assertNothingSent();

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/creator/tracks/{$track->id}/lyrics", ['lines' => [['text' => 'Not mine']]])->assertNotFound();
    }

    public function test_a_track_released_from_the_studio_takes_its_lyric_sheet(): void
    {
        Http::fake();
        app(Lyrics::class)->save($this->track, [['start' => 1, 'end' => 2, 'text' => 'From the studio']], Lyric::WRITTEN, $this->owner);

        $track = $this->release($this->owner, ['project_track_id' => $this->track->id]);
        $this->transcribe($track);

        $this->assertSame('From the studio', app(Lyrics::class)->for($track)->text);
        Http::assertNothingSent();
    }

    public function test_tracks_can_be_found_by_their_lyrics(): void
    {
        $track = $this->release($this->owner, ['title' => 'Untitled Groove']);
        app(Lyrics::class)->save($track, [
            ['start' => 0, 'end' => 2, 'text' => 'Walking down the street'],
            ['start' => 2, 'end' => 4, 'text' => 'Dancing under neon moonlight'],
        ], Lyric::WRITTEN, $this->owner);

        $found = collect($this->getJson('/api/tracks?limit=50&search=' . urlencode('neon moonlight'))->assertOk()->json('data'));

        $this->assertSame('Dancing under neon moonlight', $found->firstWhere('id', $track->id)['lyricMatch'] ?? null);

        $byTitle = collect($this->getJson('/api/tracks?limit=50&search=untitled')->json('data'))->pluck('id');
        $this->assertContains($track->id, $byTitle->all());

        $none = collect($this->getJson('/api/tracks?limit=50&search=zyxwvut')->json('data'))->pluck('id');
        $this->assertNotContains($track->id, $none->all());
    }

    public function test_words_to_review_go_to_the_moderation_queue_once(): void
    {
        $track = $this->release($this->owner);
        $lyrics = app(Lyrics::class);

        $lyrics->save($track, [['text' => 'You should kill yourself']], Lyric::WRITTEN, $this->owner);
        $lyrics->save($track, [['text' => 'You should kill yourself, I said']], Lyric::WRITTEN, $this->owner);

        $flag = Safety::query()->forItem($track)->where('type', Safety::TYPE_LYRICS)->sole();
        $this->assertSame(['kill yourself'], $flag->data['words']);
        $this->assertSame(Safety::PENDING, $flag->status);

        $moderator = User::factory()->create();
        $moderator->givePermissionTo(Permission::findOrCreate('manage-content'));
        Sanctum::actingAs($moderator);
        $queued = collect($this->getJson('/api/admin/moderation/queue?source=lyrics')->assertOk()->json('data'));
        $this->assertTrue($queued->contains(fn($row) => collect($row['reports'])->contains('id', $flag->id)));

        $flag->forceFill(['status' => Safety::DISMISSED])->save();
        $lyrics->save($track, [['text' => 'Kill yourself, again']], Lyric::WRITTEN, $this->owner);

        $this->assertSame(1, Safety::query()->forItem($track)->where('type', Safety::TYPE_LYRICS)->count());
    }

    public function test_the_check_matches_whole_words_and_admin_additions(): void
    {
        $check = app(LyricsCheck::class);

        $this->assertSame([], $check->scan('A classic class of grass and cocktails')['explicit']);
        $this->assertSame(['motherfuckin', 'shit'], $check->scan('Holy SHIT, motherfuckin’ rain')['explicit']);

        AiSettings::update(['ai_lyrics_explicit_words' => 'Wahala, gbese', 'ai_lyrics_review_words' => 'bad phrase']);

        $result = $check->scan('No wahala, just a bad   phrase');
        $this->assertSame(['wahala'], $result['explicit']);
        $this->assertSame(['bad phrase'], $result['review']);
    }

    public function test_lyrics_need_ai_access_and_credits(): void
    {
        $stem = $this->stem();

        $this->project->forceFill(['user_id' => $poor = $this->member(1)->id])->save();
        Sanctum::actingAs(User::find($poor));
        $this->postJson($this->url("stems/{$stem}/lyrics"))->assertJsonValidationErrors('credits');

        $this->assertSame(2, AiSettings::clientConfig()['costs']['lyrics']);
    }
}
