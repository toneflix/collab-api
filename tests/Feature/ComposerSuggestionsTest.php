<?php

namespace Tests\Feature;

use App\Enums\Feature;
use App\Models\Plan;
use App\Models\Song;
use App\Models\SongVersion;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Credits;
use App\Services\Composer\Ai\LyricModel;
use App\Services\Composer\ComposerSettings;
use App\Services\Composer\Songs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComposerSuggestionsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $writer;

    /** @var list<array{system: string, prompt: string}> */
    public array $asked = [];

    /** @var list<string|RuntimeException> */
    public array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.replicate.key' => 'r8_test']);
        AiSettings::update(['ai_enabled' => true]);

        $this->writer = $this->writerOnPlan(true, 10);

        $test = $this;
        $this->app->instance(LyricModel::class, new class($test) implements LyricModel
        {
            public function __construct(protected ComposerSuggestionsTest $test) {}

            public function ask(string $system, string $prompt): string
            {
                $this->test->asked[] = ['system' => $system, 'prompt' => $prompt];
                $answer = array_shift($this->test->answers) ?? '{"options": []}';

                if ($answer instanceof RuntimeException) {
                    throw $answer;
                }

                return $answer;
            }

            public function name(): string
            {
                return 'anthropic/claude-4.5-haiku';
            }

            public function available(): bool
            {
                return true;
            }
        });
    }

    protected function writerOnPlan(bool $aiTools, int $credits): User
    {
        $plan = Plan::create([
            'slug' => 'composer-ai-' . uniqid(), 'name' => 'Composer AI', 'price' => 0, 'interval' => 'month',
            'features' => [Feature::AI_TOOLS->value => $aiTools, Feature::AI_CREDITS->value => $credits],
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

    protected function song(User $user, array $attributes = []): Song
    {
        return app(Songs::class)->create($user, $attributes + [
            'title' => 'Night Drive',
            'body' => "[Verse 1]\nCity lights are humming on the late night train\nI keep my head above it but I'm soaking in the rain\n",
        ]);
    }

    public function test_writer_gets_line_options_and_pays_credits(): void
    {
        $song = $this->song($this->writer, ['settings' => ['mood' => 'hopeful']]);
        $this->answers[] = "```json\n" . '{"options": [{"lines": ["Every station whispers that the sun will rise again"]}, {"lines": ["  Counting all the windows like the beads upon a chain  "]}, {"lines": ["[Hook]"]}]}' . "\n```";
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/suggestions", ['kind' => 'line', 'line' => 3])
            ->assertOk()
            ->assertJsonPath('data.kind', 'line')
            ->assertJsonPath('data.line', 3)
            ->assertJsonCount(2, 'data.options')
            ->assertJsonPath('data.options.1.lines.0', 'Counting all the windows like the beads upon a chain')
            ->assertJsonPath('data.credits', 1)
            ->assertJsonPath('data.balance', 9);

        $prompt = $this->asked[0]['prompt'];
        $this->assertStringContainsString('Mood: hopeful', $prompt);
        $this->assertStringContainsString("soaking in the rain\n>>> WRITE HERE <<<", $prompt);
        $this->assertStringContainsString('Write the next line at the marker. Give 3 different options.', $prompt);
        $this->assertStringContainsString('Keep it clean', $this->asked[0]['system']);
        $this->assertSame(9, Credits::for($this->writer)->total());
    }

    public function test_the_unsaved_body_the_app_sends_is_used(): void
    {
        $song = $this->song($this->writer);
        $this->answers[] = '{"options": [{"lines": ["One", "Two", "Three", "Four", "Five"]}, {"lines": ["A", "B"]}]}';
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/suggestions", [
            'kind' => 'block',
            'line' => 2,
            'body' => "[Hook]\nWe ride until the morning\n",
            'instruction' => 'make it about leaving home',
        ])
            ->assertOk()
            ->assertJsonCount(4, 'data.options.0.lines')
            ->assertJsonPath('data.options.1.lines', ['A', 'B']);

        $prompt = $this->asked[0]['prompt'];
        $this->assertStringContainsString("We ride until the morning\n>>> WRITE HERE <<<", $prompt);
        $this->assertStringNotContainsString('City lights', $prompt);
        $this->assertStringContainsString('The writer asks: make it about leaving home', $prompt);
        $this->assertSame(1, $song->versions()->where('kind', SongVersion::BEFORE_AI)->count());
    }

    public function test_rewrite_marks_the_line_and_verse_costs_more(): void
    {
        $song = $this->song($this->writer);
        $this->answers[] = '{"options": [{"lines": ["City lights are glowing on the midnight train"]}]}';
        $this->answers[] = '{"options": [{"lines": ["[Verse 2]", "Line one", "Line two"]}]}';
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/suggestions", ['kind' => 'rewrite', 'line' => 2])
            ->assertOk();

        $this->assertStringContainsString(">>> REWRITE THIS LINE: City lights are humming on the late night train <<<\nI keep my head", $this->asked[0]['prompt']);

        $this->postJson("/api/creator/songs/{$song->id}/suggestions", ['kind' => 'verse', 'line' => 4])
            ->assertOk()
            ->assertJsonPath('data.options.0.lines.0', '[Verse 2]')
            ->assertJsonPath('data.credits', 2)
            ->assertJsonPath('data.balance', 7);
    }

    public function test_failed_or_empty_answers_put_the_credits_back(): void
    {
        $song = $this->song($this->writer);
        $this->answers[] = new RuntimeException('Replicate is down');
        $this->answers[] = 'Sorry, I cannot help with that.';
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$song->id}/suggestions", ['kind' => 'line', 'line' => 3])
            ->assertStatus(502);
        $this->postJson("/api/creator/songs/{$song->id}/suggestions", ['kind' => 'verse', 'line' => 3])
            ->assertStatus(502);

        $this->assertSame(10, Credits::for($this->writer)->total());
        $this->assertSame(0, $song->versions()->where('kind', SongVersion::BEFORE_AI)->count());
    }

    public function test_options_with_flagged_words_are_dropped_on_clean_songs(): void
    {
        $explicitWord = collect(config('lyrics.explicit'))->first();
        $this->assertNotEmpty($explicitWord);

        $clean = $this->song($this->writer);
        $explicit = $this->song($this->writer, ['settings' => ['explicit' => true]]);
        $answer = '{"options": [{"lines": ["You ' . $explicitWord . ' know it"]}, {"lines": ["You surely know it"]}]}';
        $this->answers = [$answer, $answer];
        $this->actingAsFresh($this->writer);

        $this->postJson("/api/creator/songs/{$clean->id}/suggestions", ['kind' => 'line', 'line' => 3])
            ->assertOk()
            ->assertJsonCount(1, 'data.options')
            ->assertJsonPath('data.options.0.lines.0', 'You surely know it');

        $this->postJson("/api/creator/songs/{$explicit->id}/suggestions", ['kind' => 'line', 'line' => 3])
            ->assertOk()
            ->assertJsonCount(2, 'data.options');

        $this->assertStringContainsString('Strong language is fine', $this->asked[1]['system']);
    }

    public function test_suggestions_need_ai_tools_credits_and_the_song_owner(): void
    {
        $free = $this->writerOnPlan(false, 10);
        $broke = $this->writerOnPlan(true, 0);
        $song = $this->song($this->writer);
        $freeSong = $this->song($free);
        $brokeSong = $this->song($broke);

        $this->actingAsFresh($free);
        $this->postJson("/api/creator/songs/{$freeSong->id}/suggestions", ['kind' => 'line', 'line' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');
        $this->postJson("/api/creator/songs/{$song->id}/suggestions", ['kind' => 'line', 'line' => 1])
            ->assertNotFound();

        $this->actingAsFresh($broke);
        $this->postJson("/api/creator/songs/{$brokeSong->id}/suggestions", ['kind' => 'line', 'line' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');
        $this->postJson("/api/creator/songs/{$brokeSong->id}/suggestions", ['kind' => 'chorus', 'line' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->assertSame([], $this->asked);
    }

    public function test_admins_set_composer_costs_and_only_lyric_models_sing_songs(): void
    {
        AiSettings::update([
            'ai_composer_line_credits' => 3,
            'ai_composer_music_model' => 'stability-ai/stable-audio-2.5',
        ]);

        $this->assertSame(3, AiSettings::composerCredits('line'));
        $this->assertTrue(AiSettings::composerMusicModel()->takesLyrics());
        $this->assertSame('anthropic/claude-4.5-haiku', AiSettings::composerModel());
        $this->assertSame(3, AiSettings::clientConfig()['costs']['composer']['line']);
    }

    public function test_admins_save_composer_settings_on_the_ai_page(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        Permission::findOrCreate('manage-configuration', 'web');
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo('manage-configuration');
        $this->actingAsFresh($admin);

        $this->putJson('/api/admin/ai', ['ai_composer_music_model' => 'elevenlabs/music', 'composer_word_source' => 'google'])
            ->assertJsonValidationErrors(['ai_composer_music_model', 'composer_word_source']);

        $this->putJson('/api/admin/ai', [
            'ai_composer_model' => 'anthropic/claude-4.5-sonnet',
            'ai_composer_verse_credits' => 4,
            'ai_composer_music_model' => 'suno/music',
            'composer_word_source' => 'local',
        ])
            ->assertAccepted()
            ->assertJsonPath('data.fields.composer_word_source.value', 'local')
            ->assertJsonPath('data.fields.ai_composer_model.value', 'anthropic/claude-4.5-sonnet')
            ->assertJsonPath('data.client.costs.composer.verse', 4)
            ->assertJsonPath('data.options.composerMusicModels', ['minimax/music-2.5', 'minimax/music-1.5', 'suno/music'])
            ->assertJsonPath('data.keys.datamuse.0.env', 'DATAMUSE_API_KEY');

        $this->assertSame('local', ComposerSettings::wordSource());
        $this->assertSame('suno/music', AiSettings::composerMusicModel()::ID);
        ComposerSettings::update(['composer_word_source' => 'datamuse']);
    }
}
