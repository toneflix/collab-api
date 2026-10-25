<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\User;
use App\Services\Realtime\Realtime;
use App\Services\Realtime\RealtimeSettings;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Toneflix\LaravelFileable\Media;

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
        config([
            'broadcasting.connections.reverb.app_id' => 'app',
            'broadcasting.connections.reverb.key' => 'key',
            'broadcasting.connections.reverb.secret' => 'secret',
            'broadcasting.connections.reverb.options.host' => 'localhost',
        ]);

        RealtimeSettings::update(['realtime_driver' => 'reverb']);
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

        app(BroadcastManager::class)->extend('reverb', fn() => $spy);
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
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_messages_page_backwards(): void
    {
        collect(range(1, 5))->map(fn($i) => $this->send($this->owner, "Message {$i}"));

        Sanctum::actingAs($this->owner);
        $newest = $this->getJson($this->url('/messages?limit=2'))->assertOk();
        $this->assertSame(['Message 4', 'Message 5'], array_column($newest->json('data'), 'body'));
        $this->assertNotNull($cursor = $newest->json('meta.next_cursor'));

        $older = $this->getJson($this->url('/messages?limit=3&cursor=' . $cursor))->assertOk();
        $this->assertSame(['Message 1', 'Message 2', 'Message 3'], array_column($older->json('data'), 'body'));
        $this->assertNull($older->json('meta.next_cursor'));
    }

    public function test_the_list_shows_latest_message_and_unread_counts(): void
    {
        $this->send($this->owner, 'One');
        $this->send($this->owner, 'Two');

        Sanctum::actingAs($this->collaborator);
        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonPath('unread', 2)
            ->assertJsonPath('data.0.key', "project-track.{$this->track->id}")
            ->assertJsonPath('data.0.title', 'Chat track')
            ->assertJsonPath('data.0.context.projectId', $this->project->id)
            ->assertJsonPath('data.0.lastMessage.body', 'Two')
            ->assertJsonPath('data.0.unread', 2);

        // The sender's own messages never count as unread.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/conversations')->assertJsonPath('unread', 0);

        Sanctum::actingAs($this->collaborator);
        $this->postJson($this->url('/read'))->assertOk();
        $this->getJson('/api/conversations')->assertJsonPath('unread', 0);
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

    public function test_voice_notes_are_stored_and_played_back(): void
    {
        Storage::fake(config('filesystems.default'));
        Sanctum::actingAs($this->collaborator);

        $id = $this->post($this->url('/messages'), [
            'voice' => UploadedFile::fake()->create('note.webm', 40, 'audio/webm'),
            'duration' => 12.4,
            'waveform' => json_encode([0.1, 0.5, 1]),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.body', '')
            ->assertJsonPath('data.voice.duration', 12.4)
            ->assertJsonPath('data.voice.waveform', [0.1, 0.5, 1])
            ->json('data.id');

        $voice = $this->getJson($this->url('/messages'))->json('data.0.voice');
        $this->assertStringContainsString('/media/files/', $voice['url']);

        // The inbox shows something readable for a message with no text.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/conversations')->assertJsonPath('data.0.lastMessage.body', 'Voice note (0:12)');

        // Deleting the message removes the recording.
        $path = (new Media)->mediaInfo('voice_notes', Comment::find($id)->meta['voice']['file'])['path'];
        Storage::disk(config('filesystems.default'))->assertExists($path);
        Sanctum::actingAs($this->collaborator);
        $this->deleteJson($this->url("/messages/{$id}"))->assertStatus(202);
        Storage::disk(config('filesystems.default'))->assertMissing($path);
    }

    public function test_a_message_needs_text_or_a_voice_note(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson($this->url('/messages'), ['body' => ''])->assertJsonValidationErrors('body');
        $this->post($this->url('/messages'), [
            'voice' => UploadedFile::fake()->create('note.txt', 2, 'text/plain'),
            'duration' => 3,
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('voice');
    }

    public function test_messages_can_point_at_part_of_the_track(): void
    {
        $stem = $this->track->stems()->forceCreate(['user_id' => $this->owner->id, 'title' => 'Vocals', 'meta' => ['name' => 'Vocals']]);
        Sanctum::actingAs($this->collaborator);

        $this->postJson($this->url('/messages'), [
            'body' => 'The breath here is loud',
            'region' => ['start' => 12.5, 'end' => 14.25, 'stem_id' => $stem->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.region.start', 12.5)
            ->assertJsonPath('data.region.end', 14.25)
            ->assertJsonPath('data.region.stemId', $stem->id)
            ->assertJsonPath('data.region.stemName', 'Vocals')
            ->assertJsonPath('data.region.trackId', $this->track->id)
            ->assertJsonPath('data.region.projectId', $this->project->id);

        $this->postJson($this->url('/messages'), ['body' => 'x', 'region' => ['start' => 5, 'end' => 2]])
            ->assertJsonValidationErrors('region.end');
        $this->postJson($this->url('/messages'), ['body' => 'x', 'region' => ['start' => 1, 'end' => 2, 'stem_id' => 999999]])
            ->assertUnprocessable();
        $this->postJson("/api/conversations/project/{$this->project->id}/messages", ['body' => 'x', 'region' => ['start' => 1, 'end' => 2]])
            ->assertUnprocessable();
    }
}
