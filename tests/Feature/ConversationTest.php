<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Realtime\Realtime;
use App\Services\Realtime\RealtimeSettings;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, array{channels: array<int, string>, event: string, payload: array<string, mixed>}> */
    protected array $sent = [];

    protected User $owner;

    protected User $collaborator;

    protected User $stranger;

    protected Project $project;

    protected ProjectTrack $track;

    protected function setUp(): void
    {
        parent::setUp();

        RealtimeSettings::flush();

        $this->owner = User::factory()->create();
        $this->collaborator = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->project = Project::query()->forceCreate([
            'user_id' => $this->owner->id,
            'title' => 'Chat test',
            'privacy' => 'private',
        ]);
        $this->project->collaborators()->forceCreate(['user_id' => $this->collaborator->id, 'role' => 'vocals', 'accepted' => true]);
        $this->track = $this->project->tracks()->forceCreate(['title' => 'Chat track', 'privacy' => 'private']);
    }

    protected function tearDown(): void
    {
        RealtimeSettings::flush();

        parent::tearDown();
    }

    protected function url(string $path = ''): string
    {
        return "/api/conversations/project-track/{$this->track->id}{$path}";
    }

    protected function send(User $user, string $body, ?int $replyTo = null): int
    {
        Sanctum::actingAs($user);

        return $this->postJson($this->url('/messages'), array_filter(['body' => $body, 'reply_to' => $replyTo]))
            ->assertCreated()
            ->json('data.id');
    }

    protected function useSpyDriver(): void
    {
        RealtimeSettings::update([
            'realtime_driver' => 'reverb',
            'reverb_app_id' => 'app',
            'reverb_key' => 'key',
            'reverb_secret' => 'secret',
            'reverb_host' => 'localhost',
            'reverb_port' => '8080',
            'reverb_scheme' => 'http',
        ]);
        Realtime::configure();

        $spy = new class($this->sent) extends Broadcaster
        {
            public function __construct(public array &$sent) {}

            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->sent[] = ['channels' => array_map('strval', $channels), 'event' => $event, 'payload' => $payload];
            }
        };

        app(BroadcastManager::class)->extend('reverb', fn () => $spy);
        app(BroadcastManager::class)->forgetDrivers();
    }

    public function test_messages_come_back_as_chat_with_quoted_replies(): void
    {
        $first = $this->send($this->owner, 'New drum stem is up');
        $this->send($this->collaborator, 'Sounds great', $first);

        Sanctum::actingAs($this->owner);
        $this->getJson($this->url('/messages'))
            ->assertOk()
            ->assertJsonPath('data.0.body', 'New drum stem is up')
            ->assertJsonPath('data.0.parent', null)
            ->assertJsonPath('data.1.body', 'Sounds great')
            ->assertJsonPath('data.1.type', 'reply')
            ->assertJsonPath('data.1.parent.id', $first)
            ->assertJsonPath('data.1.parent.user.id', $this->owner->id)
            ->assertJsonPath('data.1.user.id', $this->collaborator->id)
            ->assertJsonMissingPath('data.1.user.email')
            ->assertJsonPath('meta.hasMore', false);
    }

    public function test_messages_page_backwards(): void
    {
        $ids = collect(range(1, 5))->map(fn ($i) => $this->send($this->owner, "Message {$i}"));

        Sanctum::actingAs($this->owner);
        $newest = $this->getJson($this->url('/messages?limit=2'))->assertOk();
        $this->assertSame(['Message 4', 'Message 5'], array_column($newest->json('data'), 'body'));
        $this->assertTrue($newest->json('meta.hasMore'));

        $older = $this->getJson($this->url('/messages?limit=3&before='.$ids[3]))->assertOk();
        $this->assertSame(['Message 1', 'Message 2', 'Message 3'], array_column($older->json('data'), 'body'));
        $this->assertFalse($older->json('meta.hasMore'));
    }

    public function test_the_list_shows_latest_message_and_unread_counts(): void
    {
        $this->send($this->owner, 'One');
        $this->send($this->owner, 'Two');

        Sanctum::actingAs($this->collaborator);
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonPath('data.0.key', "project-track.{$this->track->id}")
            ->assertJsonPath('data.0.title', 'Chat track')
            ->assertJsonPath('data.0.context.projectId', $this->project->id)
            ->assertJsonPath('data.0.lastMessage.body', 'Two')
            ->assertJsonPath('data.0.unread', 2);

        // The sender's own messages never count as unread.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/conversations')->assertJsonPath('meta.unread', 0);

        Sanctum::actingAs($this->collaborator);
        $this->postJson($this->url('/read'))->assertOk();
        $this->getJson('/api/conversations')->assertJsonPath('meta.unread', 0);
    }

    public function test_outsiders_cannot_read_or_write(): void
    {
        Sanctum::actingAs($this->stranger);

        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url('/messages'))->assertForbidden();
        $this->postJson($this->url('/messages'), ['body' => 'Hi'])->assertForbidden();
        $this->getJson('/api/conversations/project-track/999999')->assertNotFound();
        $this->getJson('/api/conversations/nonsense/1')->assertNotFound();
    }

    public function test_people_delete_their_own_messages_and_owners_any(): void
    {
        $mine = $this->send($this->collaborator, 'Mine');
        $theirs = $this->send($this->owner, 'Owner says');

        Sanctum::actingAs($this->collaborator);
        $this->getJson($this->url())->assertJsonPath('data.canDeleteAny', false);
        $this->deleteJson($this->url("/messages/{$theirs}"))->assertForbidden();
        $this->deleteJson($this->url("/messages/{$mine}"))->assertStatus(202);

        $again = $this->send($this->collaborator, 'Again');
        Sanctum::actingAs($this->owner);
        $this->getJson($this->url())->assertJsonPath('data.canDeleteAny', true);
        $this->deleteJson($this->url("/messages/{$again}"))->assertStatus(202);

        $this->getJson($this->url('/messages'))->assertJsonCount(1, 'data');
    }

    public function test_messages_reach_the_conversation_and_everyone_elses_inbox(): void
    {
        $this->useSpyDriver();

        $this->send($this->collaborator, 'Harmony is in');

        $events = collect($this->sent)->keyBy('event');
        $this->assertSame(["private-conversation.project-track.{$this->track->id}"], $events['comment.posted']['channels']);
        $this->assertSame('Harmony is in', $events['comment.posted']['payload']['comment']['body']);

        $inbox = $events['conversation.posted'];
        $this->assertContains("private-user.{$this->owner->id}", $inbox['channels']);
        $this->assertNotContains("private-user.{$this->collaborator->id}", $inbox['channels']);
        $this->assertNotContains("private-user.{$this->stranger->id}", $inbox['channels']);
        $this->assertSame('Chat track', $inbox['payload']['conversation']['title']);
        $this->assertSame('Harmony is in', $inbox['payload']['message']['body']);
    }
}
