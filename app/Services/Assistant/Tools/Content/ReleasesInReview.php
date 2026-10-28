<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Release;
use App\Models\User;

class ReleasesInReview extends ContentTool
{
    public function name(): string
    {
        return 'releases_in_review';
    }

    public function description(): string
    {
        return 'Published releases waiting for an admin to approve them, oldest first, 10 a page.';
    }

    public function input(): array
    {
        return [
            'search' => ['nullable|string|max:100', 'Text in the title, version or owner\'s name'],
            'page' => $this->pageField(),
        ];
    }

    public function run(User $admin, array $input): array
    {
        $releases = $this
            ->queue()
            ->releases($input['search'] ?? null, self::PER_PAGE, $this->page($input));

        return $this->paged($releases, fn(Release $release) => [
            'id' => $release->id,
            'title' => $release->title,
            'version' => $release->version,
            'artist' => $release->artist?->name,
            'owner' => $release->user?->username,
            'explicit' => (bool) $release->explicit,
            'aiReview' => $release->latestAiReview?->only(['status', 'decision', 'confidence', 'summary']),
            'submitted' => $release->updated_at?->toIso8601String(),
        ]);
    }
}
