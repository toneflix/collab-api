<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Artist;
use App\Models\AssistantAction;
use App\Models\AssistantChat;
use App\Models\AssistantMessage;
use App\Models\Genre;
use App\Models\Project;
use App\Models\Release;
use App\Models\Takedown;
use App\Models\Track;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Services\Assistant\Ai\AssistantModel;
use App\Services\Auth\Totp;
use App\Services\Catalog\Genres;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminAssistantTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<array{system: string, prompt: string}> */
    protected array $asked = [];

    /** @var list<string> */
    protected array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
        foreach (['manage-users', 'manage-admins', 'manage-plans', 'manage-payouts', 'manage-content'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (['admin', 'super-admin'] as $name) {
            Role::findOrCreate($name, 'web');
        }

        $test = $this;
        $this->app->instance(AssistantModel::class, new class($test) implements AssistantModel
        {
            public function __construct(protected AdminAssistantTest $test) {}

            public function ask(string $system, string $prompt): string
            {
                return $this->test->answer($system, $prompt);
            }

            public function name(): string
            {
                return 'fake/assistant';
            }

            public function available(): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        AiSettings::flush();

        parent::tearDown();
    }

    public function answer(string $system, string $prompt): string
    {
        $this->asked[] = compact('system', 'prompt');

        return array_shift($this->answers) ?? json_encode(['reply' => 'Done for now.', 'calls' => []]);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $calls
     */
    protected function turn(string $reply, array $calls = []): string
    {
        return json_encode([
            'reply' => $reply,
            'calls' => array_map(fn(array $call) => ['tool' => $call[0], 'input' => $call[1]], $calls),
        ]);
    }

    protected function adminWith(string ...$permissions): User
    {
        $admin = User::factory()
            ->create();
        $admin->givePermissionTo($permissions);

        return $this->actingAsFresh($admin);
    }

    protected function actingAsFresh(User $user): User
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->controller = null;
        }

        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function ask(string $message): AssistantChat
    {
        $id = $this
            ->postJson('/api/admin/assistant/chats', ['message' => $message])
            ->assertCreated()
            ->json('data.id');

        return AssistantChat::query()
            ->findOrFail($id);
    }

    protected function pendingAction(AssistantChat $chat): AssistantAction
    {
        return $chat
            ->actions()
            ->pending()
            ->latest('id')
            ->firstOrFail();
    }

    public function test_only_admins_with_a_tool_can_use_the_assistant(): void
    {
        $this->actingAsFresh(User::factory()->create());

        $this
            ->getJson('/api/admin/assistant/chats')
            ->assertForbidden();

        $this->adminWith('manage-content');

        $this
            ->getJson('/api/admin/assistant/chats')
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_the_assistant_searches_users_and_answers(): void
    {
        $this->adminWith('manage-users');
        $target = User::factory()
            ->create(['username' => 'spammy_sam']);

        $this->answers = [
            $this->turn('Looking.', [['find_users', ['search' => 'spammy']]]),
            $this->turn('I found @spammy_sam.'),
        ];

        $chat = $this->ask('Who is spammy?');

        $this->assertSame(AssistantChat::IDLE, $chat->status);
        $this->assertCount(2, $this->asked);
        $this->assertStringContainsString('find_users', $this->asked[0]['system']);
        $this->assertStringNotContainsString('take_down', $this->asked[0]['system']);
        $this->assertStringContainsString('spammy_sam', $this->asked[1]['prompt']);

        $this
            ->getJson("/api/admin/assistant/chats/{$chat->id}/messages")
            ->assertOk()
            ->assertJsonPath('chat.status', AssistantChat::IDLE)
            ->assertJsonPath('data.0.role', AssistantMessage::ASSISTANT)
            ->assertJsonPath('data.0.content', 'I found @spammy_sam.')
            ->assertJsonPath('data.1.role', AssistantMessage::TOOL)
            ->assertJsonPath('data.1.tool', 'find_users')
            ->assertJsonPath('data.3.role', AssistantMessage::ADMIN);

        $tool = AssistantMessage::query()
            ->where('assistant_chat_id', $chat->id)
            ->where('role', AssistantMessage::TOOL)
            ->firstOrFail();
        $this->assertSame($target->id, $tool->data['result']['items'][0]['id']);
    }

    public function test_search_results_come_in_pages(): void
    {
        $this->adminWith('manage-users');
        User::factory()
            ->count(12)
            ->sequence(fn($sequence) => ['username' => "pager_{$sequence->index}"])
            ->create();

        $this->answers = [$this->turn('', [['find_users', ['search' => 'pager_', 'page' => 2]]])];

        $chat = $this->ask('List the pager accounts, second page.');

        $tool = $chat
            ->messages()
            ->where('role', AssistantMessage::TOOL)
            ->firstOrFail();
        $this->assertSame(2, $tool->data['result']['page']);
        $this->assertSame(2, $tool->data['result']['lastPage']);
        $this->assertSame(12, $tool->data['result']['total']);
        $this->assertCount(2, $tool->data['result']['items']);
    }

    public function test_suspending_waits_for_the_admin_to_confirm(): void
    {
        $admin = $this->adminWith('manage-users');
        $target = User::factory()
            ->create();

        $this->answers = [$this->turn('I can suspend them once you confirm.', [['suspend_user', ['user' => $target->username, 'days' => 7, 'reason' => 'Spam']]])];

        $chat = $this->ask("Suspend @{$target->username} for a week for spam");
        $action = $this->pendingAction($chat);

        $this->assertFalse($target->fresh()->isSuspended());
        $this->assertStringContainsString("Suspend @{$target->username}", $action->summary);

        $this
            ->getJson("/api/admin/assistant/chats/{$chat->id}/messages")
            ->assertJsonPath('data.1.role', AssistantMessage::ACTION)
            ->assertJsonPath('data.1.action.status', AssistantAction::PENDING);

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertAccepted()
            ->assertJsonPath('data.status', AssistantAction::DONE);

        $this->assertTrue($target->fresh()->isSuspended());

        $entry = AdminAction::query()
            ->where('user_id', $target->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('account.suspended', $entry->action);
        $this->assertSame($admin->id, $entry->admin_id);
        $this->assertSame('assistant', $entry->details['via']);

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertUnprocessable();
    }

    public function test_a_cancelled_action_never_runs(): void
    {
        $this->adminWith('manage-users');
        $target = User::factory()
            ->create();

        $this->answers = [$this->turn('', [['suspend_user', ['user' => (string) $target->id, 'reason' => 'Abuse']]])];

        $chat = $this->ask('Ban them');
        $action = $this->pendingAction($chat);

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', AssistantAction::CANCELLED);
        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertUnprocessable();

        $this->assertFalse($target->fresh()->isSuspended());
    }

    public function test_the_assistant_cannot_do_more_than_the_admin(): void
    {
        $this->adminWith('manage-users');
        $staff = User::factory()
            ->create();
        $staff->givePermissionTo('manage-content');

        $this->answers = [$this->turn('', [
            ['suspend_user', ['user' => $staff->username, 'reason' => 'Test']],
            ['take_down', ['type' => 'user', 'id' => $staff->id, 'reason' => 'spam']],
            ['adjust_funds', ['user' => $staff->username, 'amount' => 50, 'reason' => 'Gift']],
        ])];

        $chat = $this->ask('Suspend the moderator, take them down and give them money');

        $this->assertSame(0, $chat->actions()->count());

        $errors = $chat
            ->messages()
            ->where('role', AssistantMessage::TOOL)
            ->orderBy('id')
            ->get()
            ->map(fn(AssistantMessage $message) => $message->data['error'] ?? null)
            ->all();

        $this->assertSame('You can\'t do that to this account.', $errors[0]);
        $this->assertStringContainsString('no tool called take_down', $errors[1]);
        $this->assertStringContainsString('no tool called adjust_funds', $errors[2]);
        $this->assertStringNotContainsString('adjust_funds', $this->asked[0]['system']);
    }

    public function test_confirming_checks_the_admins_rights_again(): void
    {
        $admin = $this->adminWith('manage-users', 'manage-payouts');
        $target = User::factory()
            ->create();

        $this->answers = [$this->turn('', [['adjust_funds', ['user' => $target->username, 'amount' => -20, 'reason' => 'Chargeback']]])];

        $chat = $this->ask('Take 20 from them');
        $action = $this->pendingAction($chat);

        $admin->revokePermissionTo('manage-payouts');
        $this->actingAsFresh($admin);

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertAccepted()
            ->assertJsonPath('data.status', AssistantAction::FAILED)
            ->assertJsonPath('data.error', 'You can no longer do this.');
    }

    public function test_admins_only_see_and_confirm_their_own_chats(): void
    {
        $this->adminWith('manage-users');
        $target = User::factory()
            ->create();

        $this->answers = [$this->turn('', [['suspend_user', ['user' => $target->username, 'reason' => 'Spam']]])];

        $chat = $this->ask('Ban them');
        $action = $this->pendingAction($chat);

        $this->adminWith('manage-users');

        $this
            ->getJson("/api/admin/assistant/chats/{$chat->id}/messages")
            ->assertNotFound();
        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertNotFound();

        $this->assertFalse($target->fresh()->isSuspended());
    }

    public function test_an_old_action_expires(): void
    {
        $this->adminWith('manage-users');
        $target = User::factory()
            ->create();

        $this->answers = [$this->turn('', [['suspend_user', ['user' => $target->username, 'reason' => 'Spam']]])];

        $chat = $this->ask('Ban them');
        $action = $this->pendingAction($chat);

        $this->travel(AssistantAction::EXPIRES_AFTER + 1)->minutes();

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action' => 'This action expired. Ask the assistant again.']);

        $this->assertFalse($target->fresh()->isSuspended());
    }

    public function test_granting_credits_runs_at_once_but_taking_them_waits(): void
    {
        $this->adminWith('manage-users');
        $target = User::factory()
            ->create();

        $this->answers = [$this->turn('', [
            ['adjust_credits', ['user' => $target->username, 'credits' => 50, 'reason' => 'Promo']],
            ['adjust_credits', ['user' => $target->username, 'credits' => -10, 'reason' => 'Refund']],
        ])];

        $chat = $this->ask('Give them 50 credits, then take 10 back');

        $result = $chat
            ->messages()
            ->where('role', AssistantMessage::TOOL)
            ->firstOrFail()
            ->data['result'];

        $this->assertSame(1, $chat->actions()->count());
        $this->assertSame(50, $result['credits']['total']);
    }

    public function test_the_assistant_finds_and_takes_down_content_once_confirmed(): void
    {
        $moderator = $this->adminWith('manage-content');
        $genre = Genre::factory()
            ->create();
        $release = Release::factory()
            ->has(Track::factory()->count(1)->state(['genre_id' => $genre->id, 'audio' => null, 'title' => 'Stolen Groove']), 'tracks')
            ->create([
                'draft' => false,
                'price' => 0,
                'genre_id' => $genre->id,
                'user_id' => User::factory()->create()->id,
                'artist_id' => Artist::factory()->create()->id,
            ]);
        $track = $release
            ->tracks()
            ->firstOrFail();

        $this->answers = [
            $this->turn('', [['find_content', ['search' => 'Stolen Groove', 'type' => 'track']]]),
            $this->turn('Confirm to take it down.', [['take_down', ['type' => 'track', 'id' => $track->id, 'reason' => 'copyright']]]),
        ];

        $chat = $this->ask('Take down the track Stolen Groove for copyright');
        $action = $this->pendingAction($chat);

        $this->assertStringContainsString('Stolen Groove', $this->asked[1]['prompt']);
        $this->assertStringContainsString("track #{$track->id}", $action->summary);
        $this->assertFalse(Takedown::query()->where('model_id', $track->id)->exists());

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertAccepted()
            ->assertJsonPath('data.status', AssistantAction::DONE);

        $takedown = Takedown::query()
            ->where('model_type', $track->getMorphClass())
            ->where('model_id', $track->id)
            ->firstOrFail();
        $this->assertSame($moderator->id, $takedown->moderator_id);
    }

    public function test_deleting_content_needs_the_admins_name_and_code_on_the_card(): void
    {
        $moderator = $this->adminWith('manage-content');
        $owner = User::factory()
            ->create();
        $project = Project::query()
            ->forceCreate(['user_id' => $owner->id, 'title' => 'Spam Session', 'privacy' => 'public']);
        $propose = $this->turn('Confirm on the card.', [['delete_content', ['type' => 'project', 'id' => $project->id, 'reason' => 'spam']]]);

        // Without two-factor sign-in the assistant is told it can't.
        $this->answers = [$propose];
        $chat = $this->ask('Delete the project Spam Session');

        $this->assertSame(0, $chat->actions()->count());
        $this->assertStringContainsString('two-factor', $this->asked[1]['prompt']);

        $secret = Totp::secret();
        $moderator
            ->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])
            ->save();
        $moderator = $this->actingAsFresh($moderator);

        $this->answers = [$propose];
        $chat = $this->ask('Delete the project Spam Session');
        $action = $this->pendingAction($chat);

        $this->assertSame(['confirm_name', 'code'], array_column($action->asks, 'field'));
        $this
            ->getJson("/api/admin/assistant/chats/{$chat->id}/messages")
            ->assertJsonPath('data.1.action.asks.0.label', 'Type “Spam Session” to confirm');

        $uri = "/api/admin/assistant/actions/{$action->id}/confirm";

        $this
            ->postJson($uri, ['confirm_name' => 'Spam', 'code' => Totp::code($secret, Totp::step())])
            ->assertJsonValidationErrors(['confirm_name']);
        $this
            ->postJson($uri, ['confirm_name' => 'Spam Session', 'code' => '000000'])
            ->assertJsonValidationErrors(['code']);

        $this->assertSame(AssistantAction::PENDING, $action->fresh()->status);
        $this->assertNotNull(Project::query()->withoutGlobalScopes()->find($project->id));

        $this
            ->postJson($uri, ['confirm_name' => 'Spam Session', 'code' => Totp::code($secret, Totp::step())])
            ->assertAccepted()
            ->assertJsonPath('data.status', AssistantAction::DONE);

        $this->assertNull(Project::query()->withoutGlobalScopes()->find($project->id));
        $this->assertDatabaseHas('admin_actions', [
            'admin_id' => $moderator->id,
            'user_id' => $owner->id,
            'action' => 'content.deleted',
        ]);
        $this->assertSame('assistant', AdminAction::query()->latest('id')->value('details')['via']);
        $this->assertStringNotContainsString(Totp::code($secret, Totp::step()), json_encode($action->fresh()->toArray()));
    }

    public function test_a_plain_text_answer_is_shown_as_the_reply(): void
    {
        $this->adminWith('manage-users');

        $this->answers = ['Hello! What do you need?'];

        $chat = $this->ask('Hi');

        $this->assertSame(
            'Hello! What do you need?',
            $chat
                ->messages()
                ->where('role', AssistantMessage::ASSISTANT)
                ->value('content'),
        );
    }

    public function test_the_admin_waits_while_the_assistant_answers(): void
    {
        $admin = $this->adminWith('manage-users');

        $chat = $admin
            ->assistantChats()
            ->create(['status' => AssistantChat::THINKING]);

        $this
            ->postJson("/api/admin/assistant/chats/{$chat->id}/messages", ['message' => 'Hello?'])
            ->assertUnprocessable();
    }

    /**
     * Replicate drawing a plain square image for every artwork request.
     */
    protected function fakeArtwork(): void
    {
        Storage::fake(config('filesystems.default'));
        config([
            'ai.providers.replicate.key' => 'r8_test',
            'ai.providers.replicate.url' => 'https://replicate.test/v1',
        ]);
        AiSettings::update(['ai_artwork_engine' => 'replicate']);

        $image = imagecreatetruecolor(64, 64);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        Http::fake([
            'https://replicate.test/v1/models/black-forest-labs/flux-schnell/predictions' => Http::response([
                'id' => 'p1',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/art/1.png'],
            ], 201),
            'https://replicate.delivery/art/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
    }

    protected function genreWithCover(string $title): Genre
    {
        $genre = Genre::query()
            ->create(['title' => $title]);
        $image = UploadedFile::fake()
            ->image('cover.jpg', 64, 64);

        return app(Genres::class)
            ->cover($genre, $image);
    }

    public function test_the_assistant_draws_covers_for_genres_without_one(): void
    {
        $this->fakeArtwork();
        $this->adminWith('manage-content');

        $covered = $this->genreWithCover('Cover Test Covered');
        $cover = $covered->image;

        Genre::query()
            ->update(['image' => $cover]);

        $bare = Genre::query()
            ->create(['title' => 'Cover Test Bare', 'description' => 'Sunlit horns and talking drums']);
        $broken = Genre::query()
            ->create(['title' => 'Cover Test Broken']);
        $broken
            ->forceFill(['image' => 'gone.jpg'])
            ->saveQuietly();

        $this->answers = [$this->turn('Drawing them.', [['draw_genre_covers', ['missing' => true]]])];

        $chat = $this->ask('Give every genre without a cover one');

        $this->assertSame(0, $chat
            ->actions()
            ->count());
        $this->assertNotNull($bare->fresh()->coverUrl());
        $this->assertNotNull($broken->fresh()->coverUrl());
        $this->assertSame($cover, $covered->fresh()->image);

        Http::assertSent(fn(Request $request) => str_starts_with(
            (string) ($request['input']['prompt'] ?? ''),
            'Cover art for a music genre for Cover Test Bare music. Sunlit horns and talking drums.',
        ));
    }

    public function test_replacing_a_genre_cover_waits_for_the_admin_to_confirm(): void
    {
        $this->fakeArtwork();
        $this->adminWith('manage-content');
        $genre = $this->genreWithCover('Cover Test Replace');
        $cover = $genre->image;

        $this->answers = [$this->turn('', [['draw_genre_covers', ['genre' => 'cover test replace', 'prompt' => 'Neon waves']]])];

        $chat = $this->ask('Draw a new cover for Cover Test Replace');
        $action = $this->pendingAction($chat);

        $this->assertSame($cover, $genre->fresh()->image);
        $this->assertStringContainsString('Replace the cover of Cover Test Replace', $action->summary);

        $this
            ->postJson("/api/admin/assistant/actions/{$action->id}/confirm")
            ->assertAccepted()
            ->assertJsonPath('data.status', AssistantAction::DONE);

        $this->assertNotSame($cover, $genre->fresh()->image);
        $this->assertNotNull($genre->fresh()->coverUrl());
    }

    public function test_only_genre_managers_get_the_genre_cover_tool(): void
    {
        $this->adminWith('manage-users');

        $this->ask('Draw genre covers');

        $this->assertStringNotContainsString('draw_genre_covers', $this->asked[0]['system']);
    }
}
