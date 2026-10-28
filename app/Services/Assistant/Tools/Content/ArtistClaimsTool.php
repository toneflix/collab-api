<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\ArtistClaim;
use App\Models\User;
use App\Services\Artists\ArtistClaims;
use Illuminate\Validation\Rule;

class ArtistClaimsTool extends ContentTool
{
    public function name(): string
    {
        return 'artist_claims';
    }

    public function description(): string
    {
        return 'Claims people made on artist profiles no account manages yet, 10 a page. Pending ones come oldest first.';
    }

    public function input(): array
    {
        return [
            'status' => [['nullable', Rule::in([ArtistClaim::PENDING, ArtistClaim::APPROVED, ArtistClaim::REJECTED])], 'pending (default), approved or rejected'],
            'search' => ['nullable|string|max:100', 'Text in the artist, claimant name or email'],
            'page' => $this->pageField(),
        ];
    }

    public function run(User $admin, array $input): array
    {
        $claims = app(ArtistClaims::class)->queue(
            $input['status'] ?? ArtistClaim::PENDING,
            $input['search'] ?? null,
            self::PER_PAGE,
            $this->page($input),
        );

        return $this->paged($claims, fn(ArtistClaim $claim) => [
            'id' => $claim->id,
            'status' => $claim->status,
            'artist' => $claim->artist?->name,
            'account' => $claim->user?->username,
            'role' => $claim->role,
            'name' => $claim->name,
            'email' => $claim->email,
            'links' => $claim->links,
            'message' => str((string) $claim->message)->limit(500)->toString(),
            'hasDocument' => (bool) $claim->document,
            'unownedReleases' => $claim->artist?->unowned_releases_count,
            'aiReview' => $claim->latestAiReview?->only(['status', 'decision', 'confidence', 'summary']),
        ]);
    }
}
