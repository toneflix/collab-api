<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Services\Conversations\Conversations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Conversations as a chat: the list of a user's conversations, and each
 * conversation's messages, newest page first.
 */
class ConversationController extends Controller
{
    /**
     * The signed-in user's conversations, most recent first.
     */
    public function index(Request $request)
    {
        $list = Conversations::forUser($request->user());

        return Providers::response()->success([
            'data' => $list->all(),
            'meta' => ['unread' => $list->sum('unread')],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * What one conversation is about.
     */
    public function show(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());

        return Providers::response()->success([
            'data' => Conversations::subject($root) + [
                'unread' => Conversations::unreadCount($root, $request->user()),
                'canDeleteAny' => $this->ownsRoot($request, $root),
            ],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * A page of messages, oldest first. Without `before` it's the newest
     * page; pass the oldest id you have as `before` for the page above it.
     */
    public function messages(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());
        $limit = min(100, max(1, (int) $request->input('limit', 30)));

        $page = Conversations::messages($root)
            ->with('commentator')
            ->when($request->filled('before'), fn ($q) => $q->where('id', '<', (int) $request->input('before')))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $page->count() > $limit;

        return Providers::response()->success([
            'data' => $page->take($limit)->reverse()->values()->map(fn (Comment $c) => Conversations::message($c))->all(),
            'meta' => ['hasMore' => $hasMore],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    /**
     * Send a message, optionally as a reply to another one.
     */
    public function store(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());

        $valid = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'reply_to' => ['nullable', 'integer'],
        ], [], ['body' => 'message']);

        $target = $root;
        if (! empty($valid['reply_to'])) {
            $target = Conversations::messages($root)->find($valid['reply_to']);
            abort_unless($target, HttpStatus::UNPROCESSABLE_ENTITY->value, 'The message you replied to is gone.');
        }

        /** @var Comment $comment */
        $comment = $target->commentAsUser($request->user(), trim($valid['body']));

        Conversations::markRead($root, $request->user());
        Conversations::broadcast($comment, 'comment.posted');

        return Providers::response()->success([
            'data' => Conversations::message($comment),
            'message' => 'Message sent.',
        ], HttpStatus::CREATED);
    }

    /**
     * Delete a message. Authors can delete their own; owners anything.
     */
    public function destroy(Request $request, string $type, string $id, string $message)
    {
        $root = Conversations::find($type, $id, $request->user());

        /** @var Comment|null $comment */
        $comment = Conversations::messages($root)->find($message);
        abort_unless($comment, HttpStatus::NOT_FOUND->value, 'This message no longer exists.');
        abort_unless(
            (int) $comment->user_id === (int) $request->user()->id || $this->ownsRoot($request, $root),
            HttpStatus::FORBIDDEN->value,
            'You can only delete your own messages.'
        );

        Conversations::broadcast($comment, 'comment.deleted');
        $comment->delete();

        return Providers::response()->success([
            'data' => ['id' => (int) $message],
            'message' => 'Message deleted.',
        ], HttpStatus::ACCEPTED);
    }

    /**
     * Mark the conversation read up to now.
     */
    public function read(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());
        Conversations::markRead($root, $request->user());

        return Providers::response()->success([
            'data' => ['unread' => 0],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }

    protected function ownsRoot(Request $request, Model $root): bool
    {
        $userId = (int) $request->user()->id;

        return match (true) {
            $root instanceof ProjectTrack => (int) $root->project?->user_id === $userId,
            $root instanceof Project => (int) $root->user_id === $userId,
            $root instanceof Release => (int) $root->user_id === $userId,
            $root instanceof Track => (int) $root->release?->user_id === $userId,
            default => false,
        };
    }
}
