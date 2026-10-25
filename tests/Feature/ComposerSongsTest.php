<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\Song;
use App\Models\SongVersion;
use App\Models\Sound;
use App\Models\User;
use App\Services\Composer\Songs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComposerSongsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = $this->writerOnPlan(10);
    }

    protected function writerOnPlan(int $songs): User
    {
        $plan = Plan::create([
            'slug' => 'songs-' . uniqid(), 'name' => 'Songs', 'price' => 0, 'interval' => 'month',
            'features' => [Feature::MAX_SONGS->value => $songs],
        ]);

        $user = User::factory()
            ->create();

        $user
            ->subscriptions()
            ->create([
                'plan' => $plan->slug, 'plan_id' => $plan->id, 'amount' => 0,
                'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            ]);

        return $user->fresh();
    }

    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        Sanctum::actingAs($user = $user->fresh());

        return $user;
    }

    protected function song(array $attributes = []): Song
    {
        return app(Songs::class)->create($this->writer, $attributes + ['title' => 'Night Drive']);
    }

    public function test_max_songs_defaults_to_ten(): void
    {
        $this->assertSame(10, Feature::MAX_SONGS->fallback());
        $this->assertSame('limit', Feature::MAX_SONGS->type());
    }

    public function test_writer_creates_a_song_with_a_first_version(): void
    {
        $this->actingAsFresh($this->writer);

        $id = $this->postJson('/api/creator/songs', [
            'title' => 'Night Drive',
            'body' => "[Verse 1]\nNever thought that I could feel something so strong\n\nI know you're gonna keep it hot",
            'settings' => ['auto_suggest' => true, 'unknown' => 'x'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Night Drive')
            ->assertJsonPath('data.lineCount', 2)
            ->assertJsonPath('data.wordCount', 16)
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.settings.autoSuggest', true)
            ->assertJsonPath('data.settings.rhymeColours', true)
            ->json('data.id');

        $song = Song::query()->findOrFail($id);

        $this->assertArrayNotHasKey('unknown', $song->settings);
        $this->assertSame(1, $song->versions()->count());
    }

    public function test_song_limit_comes_from_the_plan(): void
    {
        $writer = $this->writerOnPlan(2);
        $this->actingAsFresh($writer);

        $this->postJson('/api/creator/songs', ['title' => 'One'])->assertCreated();
        $this->postJson('/api/creator/songs', ['title' => 'Two'])->assertCreated();

        $this->postJson('/api/creator/songs', ['title' => 'Three'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_list_is_paginated_searchable_and_only_mine(): void
    {
        $this->song(['title' => 'Night Drive', 'body' => "line one\nline two\nline three"]);
        $this->song(['title' => 'Morning', 'body' => 'drive slow']);

        $other = User::factory()
            ->create();

        app(Songs::class)->create($other, ['title' => 'Not mine']);

        $this->actingAsFresh($this->writer);

        $this->getJson('/api/creator/songs?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.body');

        $titles = $this->getJson('/api/creator/songs?search=DRIVE')
            ->assertOk()
            ->json('data.*.title');

        $this->assertEqualsCanonicalizing(['Night Drive', 'Morning'], $titles);

        $this->getJson('/api/creator/songs?search=night')
            ->assertOk()
            ->assertJsonPath('data.0.excerpt', ['line one', 'line two']);
    }

    public function test_songs_are_private(): void
    {
        $song = $this->song();

        $this->actingAsFresh(User::factory()->create());

        $this->getJson("/api/creator/songs/{$song->id}")->assertNotFound();
        $this->putJson("/api/creator/songs/{$song->id}", ['body' => 'x', 'revision' => 1])->assertNotFound();
        $this->deleteJson("/api/creator/songs/{$song->id}")->assertNotFound();
        $this->getJson("/api/creator/songs/{$song->id}/versions")->assertNotFound();
    }

    public function test_autosave_moves_the_revision_and_refuses_stale_saves(): void
    {
        $song = $this->song();
        $this->actingAsFresh($this->writer);

        $this->putJson("/api/creator/songs/{$song->id}", ['body' => 'First draft', 'revision' => 1])
            ->assertAccepted()
            ->assertJsonPath('data.body', 'First draft')
            ->assertJsonPath('data.revision', 2);

        $this->putJson("/api/creator/songs/{$song->id}", ['body' => 'From another tab', 'revision' => 1])
            ->assertStatus(409);

        $this->assertSame('First draft', $song->fresh()->body);
    }

    public function test_autosave_versions_at_most_every_ten_minutes(): void
    {
        $song = $this->song();
        $this->actingAsFresh($this->writer);

        $this->putJson("/api/creator/songs/{$song->id}", ['body' => 'a', 'revision' => 1])->assertAccepted();
        $this->assertSame(1, $song->versions()->count());

        $this->travel(11)->minutes();

        $this->putJson("/api/creator/songs/{$song->id}", ['body' => 'b', 'revision' => 2])->assertAccepted();
        $this->assertSame(2, $song->versions()->count());
        $this->assertSame('b', $song->versions()->latest('id')->value('body'));
    }

    public function test_settings_merge_and_beat_must_be_mine_or_library(): void
    {
        $song = $this->song(['settings' => ['auto_suggest' => true]]);
        $this->actingAsFresh($this->writer);

        $this->putJson("/api/creator/songs/{$song->id}", ['settings' => ['mood' => 'moody'], 'revision' => 1])
            ->assertAccepted()
            ->assertJsonPath('data.settings.autoSuggest', true)
            ->assertJsonPath('data.settings.mood', 'moody');

        $theirs = Sound::query()->forceCreate([
            'user_id' => User::factory()->create()->id,
            'source' => 'upload',
            'name' => 'Their beat',
            'file' => 'x.mp3',
        ]);

        $this->putJson("/api/creator/songs/{$song->id}", ['beat_sound_id' => $theirs->id, 'revision' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('beat_sound_id');
    }

    public function test_named_versions_and_restore(): void
    {
        $song = $this->song(['body' => 'Original']);
        $this->actingAsFresh($this->writer);

        $version = $this->postJson("/api/creator/songs/{$song->id}/versions", ['name' => 'Demo take'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'named')
            ->json('data.id');

        $this->putJson("/api/creator/songs/{$song->id}", ['body' => 'Rewritten', 'revision' => 1])->assertAccepted();

        $this->postJson("/api/creator/songs/{$song->id}/versions/{$version}/restore")
            ->assertAccepted()
            ->assertJsonPath('data.body', 'Original')
            ->assertJsonPath('data.revision', 3);

        $this->getJson("/api/creator/songs/{$song->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.kind', SongVersion::BEFORE_RESTORE)
            ->assertJsonPath('data.0.body', 'Rewritten');
    }

    public function test_versions_of_another_song_are_not_found(): void
    {
        $song = $this->song();
        $other = $this->song(['title' => 'Other']);
        $version = $other->versions()->first();

        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/versions/{$version->id}/restore")
            ->assertNotFound();
    }

    public function test_prune_keeps_named_and_latest_versions(): void
    {
        $song = $this->song();
        $songs = app(Songs::class);

        $songs->snapshot($song, $this->writer, SongVersion::NAMED, 'Keep');
        $songs->snapshot($song, $this->writer, SongVersion::AUTOSAVE);

        $this->travel(40)->days();

        $latest = $songs->snapshot($song, $this->writer, SongVersion::AUTOSAVE);

        $this->artisan('studio:prune-history')->assertSuccessful();

        $kinds = $song
            ->versions()
            ->orderBy('id')
            ->pluck('kind', 'id');

        $this->assertSame([SongVersion::NAMED, SongVersion::AUTOSAVE], $kinds->values()->all());
        $this->assertTrue($kinds->has($latest->id));
    }

    public function test_deleting_a_song_hides_it(): void
    {
        $song = $this->song();
        $this->actingAsFresh($this->writer);

        $this->deleteJson("/api/creator/songs/{$song->id}")->assertAccepted();
        $this->getJson("/api/creator/songs/{$song->id}")->assertNotFound();
        $this->assertSoftDeleted($song);
    }
}
