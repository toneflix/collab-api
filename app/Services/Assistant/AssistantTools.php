<?php

namespace App\Services\Assistant;

use App\Models\User;
use App\Services\Assistant\Tools\AssistantTool;
use App\Services\Assistant\Tools\Content;
use App\Services\Assistant\Tools\Users;

/**
 * Every tool the admin assistant has, and the ones a given admin may use.
 */
class AssistantTools
{
    /**
     * @var list<class-string<AssistantTool>>
     */
    public const TOOLS = [
        Users\FindUsers::class,
        Users\ShowUser::class,
        Users\SuspendUser::class,
        Users\UnsuspendUser::class,
        Users\SetVerified::class,
        Users\SetRoles::class,
        Users\SetPlan::class,
        Users\AdjustFunds::class,
        Users\AdjustCredits::class,
        Users\UpdateProfile::class,
        Content\ModerationQueueTool::class,
        Content\FindContent::class,
        Content\ShowContent::class,
        Content\TakeDown::class,
        Content\DeleteContent::class,
        Content\CloseReports::class,
        Content\Takedowns::class,
        Content\RestoreTakedown::class,
        Content\UpholdTakedown::class,
        Content\ReleasesInReview::class,
        Content\ReviewRelease::class,
        Content\ArtistClaimsTool::class,
        Content\ReviewClaim::class,
        Content\DrawGenreCovers::class,
    ];

    /**
     * The tools the admin's permissions allow, by name.
     *
     * @return array<string, AssistantTool>
     */
    public function for(User $admin): array
    {
        return collect(self::TOOLS)
            ->map(fn(string $class) => app($class))
            ->filter(fn(AssistantTool $tool) => $tool->allows($admin))
            ->keyBy(fn(AssistantTool $tool) => $tool->name())
            ->all();
    }

    /**
     * One of the admin's tools, or null when it doesn't exist or isn't theirs.
     */
    public function find(User $admin, string $name): ?AssistantTool
    {
        return $this->for($admin)[$name] ?? null;
    }
}
