<?php

namespace App\Models;

use App\Models\Scopes\HideReportedModels;
use App\Models\Scopes\RestrictBlockedUserScope;
use BeyondCode\Comments\Comment as BeyondCodeComment;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string|null $root_type
 * @property int|null $root_id
 */
#[ScopedBy([
    RestrictBlockedUserScope::class,
    HideReportedModels::class,
])]
class Comment extends BeyondCodeComment
{
    public static function boot(): void
    {
        parent::boot();

        // Record the conversation (project track, track, release or project)
        // a comment belongs to; a reply takes its parent's.
        static::creating(function (self $comment) {
            if ($comment->commentable_type === static::class) {
                $parent = static::withoutGlobalScopes()->find($comment->commentable_id);
                $comment->root_type = $parent?->root_type;
                $comment->root_id = $parent?->root_id;
            } else {
                $comment->root_type = $comment->commentable_type;
                $comment->root_id = $comment->commentable_id;
            }
        });
    }

    /**
     * The conversation this comment is part of.
     */
    public function root(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The comment this one replies to, if it's a reply.
     */
    public function parent(): MorphTo
    {
        return $this->morphTo('commentable')->withoutGlobalScopes();
    }
}
