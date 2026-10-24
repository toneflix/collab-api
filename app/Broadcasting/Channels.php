<?php

namespace App\Broadcasting;

use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The realtime channels and who may listen on them. Every channel is
 * private, so the client must be signed in and pass these checks.
 */
class Channels
{
    /**
     * Conversation channel types and the models they belong to.
     *
     * @var array<string, class-string<Model>>
     */
    public const CONVERSATIONS = [
        'project' => Project::class,
        'project-track' => ProjectTrack::class,
        'track' => Track::class,
        'release' => Release::class,
    ];

    public static function register(Broadcaster $broadcaster): void
    {
        // Live edits in a project track's editor.
        $broadcaster->channel('project-track.{id}', function (User $user, string $id) {
            $track = ProjectTrack::with('project')->find($id);

            return $track && static::canView($user, $track);
        });

        // Comments on a project, project track, track or release.
        $broadcaster->channel('conversation.{type}.{id}', function (User $user, string $type, string $id) {
            $class = static::CONVERSATIONS[$type] ?? null;
            $model = $class ? $class::query()->find($id) : null;

            return $model && static::canView($user, $model);
        });

        // Things meant for one user only.
        $broadcaster->channel('user.{id}', function (User $user, string $id) {
            return (string) $user->id === $id;
        });
    }

    /**
     * Whether a user can see a model, and so listen to its channels. Public
     * items are open to everyone signed in; private ones to their owner and,
     * for projects, the accepted collaborators.
     */
    public static function canView(User $user, Model $model): bool
    {
        if ($model instanceof Project || $model instanceof ProjectTrack) {
            return Gate::forUser($user)->allows('view', $model);
        }

        if ($model->privacy === 'public') {
            return true;
        }

        $ownerId = match (true) {
            $model instanceof Release => $model->user_id,
            $model instanceof Track => $model->release?->user_id,
            default => null,
        };

        return $ownerId !== null && (int) $ownerId === (int) $user->id;
    }

    /**
     * The conversation channel for something people comment on. Replies go
     * to the channel of the item the thread belongs to.
     */
    public static function conversation(Model $commentable): ?PrivateChannel
    {
        $guard = 0;
        while ($commentable instanceof Comment && $guard++ < 10) {
            $commentable = $commentable->commentable;
        }

        $type = $commentable ? array_search($commentable::class, static::CONVERSATIONS, true) : false;

        return $type ? new PrivateChannel("conversation.{$type}.{$commentable->getKey()}") : null;
    }

    public static function projectTrack(ProjectTrack|int|string $track): PrivateChannel
    {
        $id = $track instanceof ProjectTrack ? $track->getKey() : $track;

        return new PrivateChannel("project-track.{$id}");
    }

    public static function user(User|int|string $user): PrivateChannel
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return new PrivateChannel("user.{$id}");
    }
}
