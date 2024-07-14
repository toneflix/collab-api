<?php

namespace App\Models;

use App\Models\Scopes\HideReportedModels;
use App\Models\Scopes\RestrictBlockedUserScope;
use BeyondCode\Comments\Comment as BeyondCodeComment;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

#[ScopedBy([
    RestrictBlockedUserScope::class,
    HideReportedModels::class
])]
class Comment extends BeyondCodeComment
{
}