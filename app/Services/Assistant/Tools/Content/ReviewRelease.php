<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\Release;
use App\Models\User;
use App\Services\Releases\ReleaseVersions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewRelease extends ContentTool
{
    public function name(): string
    {
        return 'review_release';
    }

    public function description(): string
    {
        return 'Approve a release waiting for review so it goes live, or reject it back to its owner as a draft with a note.';
    }

    public function input(): array
    {
        return [
            'release' => ['required|integer|min:1', 'The release\'s id'],
            'decision' => [['required', Rule::in(['approve', 'reject'])], 'approve or reject'],
            'note' => ['required_if:decision,reject|nullable|string|max:2000', 'What the owner must change, required to reject'],
        ];
    }

    public function confirms(array $input): bool
    {
        return ($input['decision'] ?? null) === 'reject';
    }

    public function summary(User $admin, array $input): string
    {
        $release = $this->release($input);

        return "Reject the release #{$release->id} “{$release->title}”: {$input['note']}";
    }

    public function run(User $admin, array $input): array
    {
        $release = $this->release($input);
        $versions = app(ReleaseVersions::class);

        $input['decision'] === 'approve'
            ? $versions->approve($release, $admin)
            : $versions->reject($release, $admin, $input['note']);

        return [
            'id' => $release->id,
            'title' => $release->title,
            'reviewStatus' => $release->review_status,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function release(array $input): Release
    {
        $release = Release::query()
            ->withoutGlobalScopes()
            ->find($input['release']);

        if (! $release?->isInReview()) {
            throw ValidationException::withMessages(['release' => "Release #{$input['release']} is not waiting for review."]);
        }

        return $release;
    }

    protected function confirmNote(): string
    {
        return ' Rejecting needs the admin\'s confirmation.';
    }
}
