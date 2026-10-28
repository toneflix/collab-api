<?php

namespace App\Services\Assistant\Tools\Content;

use App\Models\ArtistClaim;
use App\Models\User;
use App\Services\Artists\ArtistClaims;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewClaim extends ContentTool
{
    public function name(): string
    {
        return 'review_claim';
    }

    public function description(): string
    {
        return 'Approve an artist claim, which hands the artist profile and its unowned releases to the claimant, or decline it with a note.';
    }

    public function input(): array
    {
        return [
            'claim' => ['required|integer|min:1', 'The claim\'s id'],
            'decision' => [['required', Rule::in(['approve', 'reject'])], 'approve or reject'],
            'note' => ['required_if:decision,reject|nullable|string|max:2000', 'A note for the claimant, required to reject'],
        ];
    }

    public function confirms(array $input): bool
    {
        return true;
    }

    public function summary(User $admin, array $input): string
    {
        $claim = $this->claim($input);
        $who = $claim->user ? "@{$claim->user->username}" : $claim->name;

        return $input['decision'] === 'approve'
            ? "Approve {$who}'s claim on {$claim->artist?->name} and hand them the artist profile"
            : "Decline {$who}'s claim on {$claim->artist?->name}: {$input['note']}";
    }

    public function run(User $admin, array $input): array
    {
        $claim = $this->claim($input);
        $claims = app(ArtistClaims::class);

        $input['decision'] === 'approve'
            ? $claims->approve($claim, $admin, $input['note'] ?? null)
            : $claims->reject($claim, $admin, $input['note']);

        return ['id' => $claim->id, 'status' => $claim->status];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function claim(array $input): ArtistClaim
    {
        $claim = ArtistClaim::query()
            ->with(['user', 'artist'])
            ->find($input['claim']);

        if (! $claim?->isPending()) {
            throw ValidationException::withMessages(['claim' => "Claim #{$input['claim']} is not waiting for review."]);
        }

        return $claim;
    }

    protected function confirmNote(): string
    {
        return ' Needs the admin\'s confirmation.';
    }
}
