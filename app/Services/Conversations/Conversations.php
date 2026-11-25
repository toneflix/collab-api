<?php

namespace App\Services\Conversations;

use App\Broadcasting\Channels;
use App\Http\Resources\ConversationMessageResource;
use App\Jobs\NotifyUnreadMessages;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Realtime\Realtime;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Toneflix\LaravelFileable\Media;

/**
 * Conversations are the comments on a project, project track, track or
 * release, read as a chat: every message (replies included) in order, with
 * read state per user.
 */
class Conversations
{
    /**
     * @return class-string<Model>|null
     */
    public static function classFor(string $type): ?string
    {
        return Channels::CONVERSATIONS[$type] ?? null;
    }

    public static function typeOf(Model|string $model): ?string
    {
        $class = is_string($model) ? $model : $model::class;
        $type = array_search($class, Channels::CONVERSATIONS, true);

        return $type === false ? null : $type;
    }

    /**
     * The conversation's subject, if it exists and the user (or a guest) may
     * see it.
     */
    public static function find(string $type, string|int $id, ?User $user): Model
    {
        $class = static::classFor($type);
        abort_unless($class, 404, 'There is no such conversation.');

        $model = $class::query()
            ->find($id);
        abort_unless($model, 404, 'This conversation no longer exists.');
        abort_unless(Channels::canView($user, $model), 403, 'You can\'t see this conversation.');

        return $model;
    }

    public static function messages(Model $root)
    {
        return Comment::query()
            ->where('root_type', $root::class)
            ->where('root_id', $root->getKey());
    }

    /**
     * A user as shown next to their messages.
     *
     * @return array<string, mixed>|null
     */
    public static function person(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->fullname ?: $user->username,
            'username' => $user->username,
            'imageUrl' => $user->images['image'] ?? null,
        ] : null;
    }

    /**
     * A voice note attached to a message, with a link to play it.
     *
     * @return array{url: string, duration: float, waveform: array<int, float>, mime: string|null}|null
     */
    public static function voice(Comment $comment): ?array
    {
        $voice = $comment->meta['voice'] ?? null;
        if (! $voice || empty($voice['file'])) {
            return null;
        }

        return [
            'url' => (new Media)->mediaInfo('voice_notes', $voice['file'])['dynamicLink'],
            'duration' => (float) ($voice['duration'] ?? 0),
            'waveform' => $voice['waveform'] ?? [],
            'mime' => $voice['mime'] ?? null,
        ];
    }

    /**
     * The part of a project track a message is about.
     *
     * @return array<string, mixed>|null
     */
    public static function region(Comment $comment): ?array
    {
        return $comment->meta['region'] ?? null;
    }

    /**
     * A short text version of a message, for previews and quotes.
     */
    public static function preview(Comment $comment, int $limit = 120): string
    {
        $text = trim((string) $comment->comment);
        $voice = $comment->meta['voice'] ?? null;

        if ($text === '' && $voice) {
            $seconds = (int) round($voice['duration'] ?? 0);
            $text = sprintf('Voice note (%d:%02d)', intdiv($seconds, 60), $seconds % 60);
        }

        return str($text)
            ->limit($limit)
            ->toString();
    }

    /**
     * Remove a message's voice note file.
     */
    public static function forgetVoice(Comment $comment): void
    {
        $file = $comment->meta['voice']['file'] ?? null;
        if ($file) {
            (new Media)->delete('voice_notes', $file);
        }
    }

    /**
     * What a conversation is about, for headers and the conversation list.
     *
     * @return array<string, mixed>
     */
    public static function subject(Model $root): array
    {
        $type = static::typeOf($root);

        [$title, $subtitle, $image, $context] = match (true) {
            $root instanceof ProjectTrack => [
                $root->title,
                $root->project?->title,
                $root->project?->images['image'] ?? null,
                ['projectId' => $root->project_id],
            ],
            $root instanceof Project => [$root->title, 'Project', $root->images['image'] ?? null, []],
            $root instanceof Track => [
                $root->title,
                $root->artist?->name,
                $root->files['image'] ?? null,
                ['artistId' => $root->artist?->username ?: '-', 'slug' => $root->slug],
            ],
            $root instanceof Release => [
                $root->title,
                $root->artist?->name,
                $root->files['artwork'] ?? null,
                ['artistId' => $root->artist?->username ?: '-', 'slug' => $root->slug],
            ],
            default => [class_basename($root), null, null, []],
        };

        return [
            'key' => "{$type}.{$root->getKey()}",
            'type' => $type,
            'id' => $root->getKey(),
            'title' => $title,
            'subtitle' => $subtitle,
            'imageUrl' => $image,
            'context' => $context,
        ];
    }

    /**
     * Conversations the user is part of, most recent first: ones they wrote
     * in, and those on the projects and project tracks they own or
     * collaborate on. Each item is the conversation's subject, with its
     * latest message and the user's unread count.
     */
    public static function forUser(User $user, int $perPage = 50): LengthAwarePaginator
    {
        $projectIds = Project::query()
            ->withoutGlobalScopes()
            ->select('projects.id')
            ->where(fn($q) => $q
                ->where('user_id', $user->id)
                ->orWhereHas('collaborators', fn($q) => $q->where('user_id', $user->id)->where('accepted', true)));
        $trackIds = ProjectTrack::query()
            ->withoutGlobalScopes()
            ->select('project_tracks.id')
            ->whereIn('project_id', $projectIds);

        $page = Comment::query()
            ->whereNotNull('comments.root_type')
            ->where(function ($q) use ($user, $projectIds, $trackIds) {
                $q->whereExists(fn($sub) => $sub->select(DB::raw(1))
                    ->from('comments as mine')
                    ->where('mine.user_id', $user->id)
                    ->whereColumn('mine.root_type', 'comments.root_type')
                    ->whereColumn('mine.root_id', 'comments.root_id'))
                    ->orWhere(fn($q) => $q->where('comments.root_type', ProjectTrack::class)->whereIn('comments.root_id', $trackIds))
                    ->orWhere(fn($q) => $q->where('comments.root_type', Project::class)->whereIn('comments.root_id', $projectIds));
            })
            ->groupBy('comments.root_type', 'comments.root_id')
            ->selectRaw('comments.root_type, comments.root_id, max(comments.id) as last_id')
            ->orderByDesc('last_id')
            ->toBase()
            ->paginate($perPage);

        $rows = $page->getCollection();
        $last = Comment::query()
            ->with('commentator')
            ->whereIn('id', $rows->pluck('last_id'))
            ->get()
            ->keyBy('id');
        $reads = $rows->isEmpty() ? collect() : DB::table('conversation_reads')
            ->where('user_id', $user->id)
            ->where(fn($q) => $rows->each(fn($row) => $q->orWhere(fn($q) => $q
                ->where('root_type', $row->root_type)
                ->where('root_id', $row->root_id))))
            ->get()
            ->keyBy(fn($r) => $r->root_type . '#' . $r->root_id);

        return $page->setCollection($rows->map(function ($row) use ($user, $last, $reads) {
            $class = $row->root_type;
            $root = class_exists($class) ? $class::query()->find($row->root_id) : null;
            if (! $root || ! Channels::canView($user, $root)) {
                return null;
            }

            $readAt = $reads->get($class . '#' . $row->root_id)?->read_at;

            return $root
                ->setRelation('latestMessage', $last->get($row->last_id))
                ->setAttribute('unread_count', static::unreadCount($root, $user, $readAt));
        })->filter()->values());
    }

    /**
     * A page of a conversation's messages, newest first.
     */
    public static function messagesPage(Model $root, int $perPage = 30): CursorPaginator
    {
        return static::messages($root)
            ->with('commentator')
            ->withCount('comments as replies_count')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Whether the user owns what the conversation is about, so may delete
     * anyone's messages in it.
     */
    public static function ownedBy(Model $root, ?User $user): bool
    {
        $userId = (int) $user?->id;

        return $userId > 0 && match (true) {
            $root instanceof ProjectTrack => (int) $root->project?->user_id === $userId,
            $root instanceof Project => (int) $root->user_id === $userId,
            $root instanceof Release => (int) $root->user_id === $userId,
            $root instanceof Track => (int) $root->release?->user_id === $userId,
            default => false,
        };
    }

    public static function unreadCount(Model $root, ?User $user, mixed $readAt = false): int
    {
        if (! $user) {
            return 0;
        }

        if ($readAt === false) {
            $readAt = DB::table('conversation_reads')
                ->where(['user_id' => $user->id, 'root_type' => $root::class, 'root_id' => $root->getKey()])
                ->value('read_at');
        }

        return static::messages($root)
            ->where('user_id', '!=', $user->id)
            ->when($readAt, fn($q) => $q->where('created_at', '>', $readAt))
            ->count();
    }

    public static function markRead(Model $root, User $user): void
    {
        DB::table('conversation_reads')
            ->updateOrInsert(
                ['user_id' => $user->id, 'root_type' => $root::class, 'root_id' => $root->getKey()],
                ['read_at' => now()],
            );
    }

    /**
     * Everyone who should hear about a new message: people who wrote in the
     * conversation, and the owner and collaborators of its project.
     *
     * @return Collection<int, int>
     */
    public static function participants(Model $root): Collection
    {
        $ids = static::messages($root)
            ->withoutGlobalScopes()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $project = match (true) {
            $root instanceof ProjectTrack => $root->project,
            $root instanceof Project => $root,
            default => null,
        };

        if ($project) {
            $ids = $ids->push($project->user_id)
                ->merge($project->collaborators()->where('accepted', true)->pluck('user_id'));
        }

        if ($root instanceof Release) {
            $ids->push($root->user_id);
        }

        return $ids
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * A new message: broadcast it, and queue a notification for the people
     * who should hear about it when they're away.
     */
    public static function posted(Comment $comment): void
    {
        static::broadcast($comment, 'comment.posted');

        $root = $comment->root_type && class_exists($comment->root_type)
            ? $comment->root_type::query()->find($comment->root_id)
            : null;

        if (! $root) {
            return;
        }

        $delay = now()
            ->addMinutes(NotifyUnreadMessages::waitMinutes());

        static::recipients($root, $comment)
            ->reject(fn(int $id) => $id === (int) $comment->user_id)
            ->each(fn(int $id) => NotifyUnreadMessages::dispatch($id, $root::class, $root->getKey())->delay($delay));
    }

    /**
     * Who gets notified about a message: the project's owner and
     * collaborators, or the release or track's owner, plus the author of the
     * message being replied to.
     *
     * @return Collection<int, int>
     */
    public static function recipients(Model $root, Comment $comment): Collection
    {
        $project = match (true) {
            $root instanceof ProjectTrack => $root->project,
            $root instanceof Project => $root,
            default => null,
        };

        $ids = collect();

        if ($project) {
            $ids->push($project->user_id)
                ->push(...$project->collaborators()->where('accepted', true)->limit(200)->pluck('user_id'));
        }

        if ($root instanceof Release) {
            $ids->push($root->user_id);
        }

        if ($root instanceof Track) {
            $ids->push($root->release?->user_id);
        }

        if ($comment->commentable_type === Comment::class) {
            $ids->push(Comment::withoutGlobalScopes()->whereKey($comment->commentable_id)->value('user_id'));
        }

        return $ids
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Broadcast a new or deleted message: to everyone viewing the
     * conversation, and to the participants' own channels so their
     * conversation list and unread counts update anywhere in the app.
     */
    public static function broadcast(Comment $comment, string $event): void
    {
        if (! Realtime::enabled()) {
            return;
        }

        $comment->loadMissing('commentator');
        $root = $comment->root_type && class_exists($comment->root_type)
            ? $comment->root_type::query()->find($comment->root_id)
            : null;
        $message = (new ConversationMessageResource($comment))->resolve();

        Realtime::send(Channels::conversation($root ?? $comment->commentable), $event, ['comment' => $message]);

        if (! $root) {
            return;
        }

        $sender = (int) $comment->user_id;
        $channels = static::participants($root)
            ->reject(fn($id) => $event === 'comment.posted' && $id === $sender)
            ->take(100)
            ->map(fn($id) => Channels::user($id))
            ->all();

        Realtime::send($channels, 'conversation.' . str($event)->after('.'), [
            'conversation' => static::subject($root),
            'message' => $message,
        ]);
    }
}
