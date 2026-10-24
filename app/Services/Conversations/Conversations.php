<?php

namespace App\Services\Conversations;

use App\Broadcasting\Channels;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Realtime\Realtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * The conversation's subject, if it exists and the user may see it.
     */
    public static function find(string $type, string|int $id, User $user): Model
    {
        $class = static::classFor($type);
        abort_unless($class, 404, 'There is no such conversation.');

        $model = $class::query()->find($id);
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
     * A message for the chat. Also carries the fields the older comment
     * views read (`cid`, `type`, `userId`, `comment`, `repliesCount`).
     *
     * @return array<string, mixed>
     */
    public static function message(Comment $comment): array
    {
        $comment->loadMissing(['commentator']);
        $isReply = $comment->commentable_type === Comment::class;
        $parent = $isReply ? Comment::withoutGlobalScopes()->with('commentator')->find($comment->commentable_id) : null;

        return [
            'id' => $comment->id,
            'cid' => $comment->commentable_id,
            'type' => $isReply ? 'reply' : 'comment',
            'body' => $comment->comment,
            'comment' => $comment->comment,
            'user' => static::person($comment->commentator),
            'userId' => $comment->user_id,
            'parent' => $parent ? [
                'id' => $parent->id,
                'body' => str($parent->comment)->limit(140)->toString(),
                'user' => static::person($parent->commentator),
            ] : null,
            'conversation' => $comment->root_type ? [
                'type' => static::typeOf($comment->root_type),
                'id' => $comment->root_id,
            ] : null,
            'isApproved' => (bool) $comment->is_approved,
            'repliesCount' => Comment::query()
                ->where('commentable_type', Comment::class)
                ->where('commentable_id', $comment->id)
                ->count(),
            'createdAt' => $comment->created_at,
        ];
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
     * collaborate on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function forUser(User $user, int $limit = 50): Collection
    {
        $projectIds = Project::query()
            ->where('user_id', $user->id)
            ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $user->id)->where('accepted', true))
            ->pluck('id');
        $trackIds = ProjectTrack::query()->whereIn('project_id', $projectIds)->pluck('id');

        $latest = Comment::query()
            ->whereNotNull('comments.root_type')
            ->where(function ($q) use ($user, $projectIds, $trackIds) {
                $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('comments as mine')
                    ->where('mine.user_id', $user->id)
                    ->whereColumn('mine.root_type', 'comments.root_type')
                    ->whereColumn('mine.root_id', 'comments.root_id'))
                    ->orWhere(fn ($q) => $q->where('comments.root_type', ProjectTrack::class)->whereIn('comments.root_id', $trackIds))
                    ->orWhere(fn ($q) => $q->where('comments.root_type', Project::class)->whereIn('comments.root_id', $projectIds));
            })
            ->groupBy('comments.root_type', 'comments.root_id')
            ->selectRaw('comments.root_type, comments.root_id, max(comments.id) as last_id')
            ->orderByDesc('last_id')
            ->limit($limit)
            ->toBase()
            ->get();

        $last = Comment::query()->with('commentator')->whereIn('id', $latest->pluck('last_id'))->get()->keyBy('id');
        $reads = DB::table('conversation_reads')->where('user_id', $user->id)->get()
            ->keyBy(fn ($r) => $r->root_type.'#'.$r->root_id);

        return $latest->map(function ($row) use ($user, $last, $reads) {
            $class = $row->root_type;
            $root = class_exists($class) ? $class::query()->find($row->root_id) : null;
            if (! $root || ! Channels::canView($user, $root)) {
                return null;
            }

            $message = $last->get($row->last_id);
            $readAt = $reads->get($class.'#'.$row->root_id)?->read_at;

            return static::subject($root) + [
                'lastMessage' => $message ? [
                    'id' => $message->id,
                    'body' => str($message->comment)->limit(120)->toString(),
                    'user' => static::person($message->commentator),
                    'createdAt' => $message->created_at,
                ] : null,
                'unread' => static::unreadCount($root, $user, $readAt),
                'updatedAt' => $message?->created_at,
            ];
        })->filter()->values();
    }

    public static function unreadCount(Model $root, User $user, mixed $readAt = false): int
    {
        if ($readAt === false) {
            $readAt = DB::table('conversation_reads')
                ->where(['user_id' => $user->id, 'root_type' => $root::class, 'root_id' => $root->getKey()])
                ->value('read_at');
        }

        return static::messages($root)
            ->where('user_id', '!=', $user->id)
            ->when($readAt, fn ($q) => $q->where('created_at', '>', $readAt))
            ->count();
    }

    public static function markRead(Model $root, User $user): void
    {
        DB::table('conversation_reads')->updateOrInsert(
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
        $ids = static::messages($root)->withoutGlobalScopes()->whereNotNull('user_id')->distinct()->pluck('user_id');

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

        return $ids->filter()->map(fn ($id) => (int) $id)->unique()->values();
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
        $message = static::message($comment);

        Realtime::send(Channels::conversation($root ?? $comment->commentable), $event, ['comment' => $message]);

        if (! $root) {
            return;
        }

        $sender = (int) $comment->user_id;
        $channels = static::participants($root)
            ->reject(fn ($id) => $event === 'comment.posted' && $id === $sender)
            ->take(100)
            ->map(fn ($id) => Channels::user($id))
            ->all();

        Realtime::send($channels, 'conversation.'.str($event)->after('.'), [
            'conversation' => static::subject($root),
            'message' => $message,
        ]);
    }
}
