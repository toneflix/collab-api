<?php

namespace App\Services\Moderation\Ai;

use App\Models\ArtistClaim;
use App\Models\Release;
use App\Services\Artists\ArtistClaims;

/**
 * @property ArtistClaim $subject
 */
class ArtistClaimCase extends ReviewCase
{
    public function __construct(ArtistClaim $claim, protected ArtistClaims $claims)
    {
        parent::__construct($claim);
    }

    public function label(): string
    {
        return 'artist claim';
    }

    public function isPending(): bool
    {
        return $this->subject->isPending() && (bool) $this->subject->artist?->isClaimable();
    }

    public function guidelines(): string
    {
        return <<<'TEXT'
            Someone asks to manage an artist profile on a music platform. Approving links the artist to their account
            and hands them the artist's unowned releases, so a wrong approval lets a stranger take over an artist.
            You cannot open the links or the document; judge only from what is written here.
            Approve only when the evidence clearly connects the claimant to the artist: the claimant's name, email
            domain or account matches the artist or a label named on the releases, and the links point at the
            artist's own pages or a distributor or label page about them. Reject claims that are vague, copy-pasted,
            point at unrelated pages, or where the claimant's details don't match anything about the artist.
            A supporting document helps but can't be checked by you, so it alone is not enough.
            TEXT;
    }

    public function brief(): string
    {
        $claim = $this->subject;
        $artist = $claim->artist;
        $user = $claim->user;

        $releases = Release::query()
            ->withoutGlobalScopes()
            ->where('artist_id', $artist->id)
            ->latest('id')
            ->limit(10)
            ->get(['title', 'record_label', 'publisher', 'user_id']);

        $catalogue = $releases->map(fn (Release $release) => sprintf(
            '- "%s", label: %s, publisher: %s, %s',
            $release->title,
            $release->record_label ?: 'none',
            $release->publisher ?: 'none',
            $release->user_id ? 'uploaded by another account' : 'owned by no account',
        ))->join("\n") ?: '- (no releases)';

        $others = ArtistClaim::query()->pending()->where('artist_id', $artist->id)->whereKeyNot($claim->id)->count();

        return implode("\n", [
            'ARTIST',
            "Name: {$artist->name}",
            "Username: @{$artist->username}",
            'About: '.$this->clip($artist->about, 600),
            'Latest releases:',
            $catalogue,
            '',
            'CLAIMANT',
            'Says they are: '.$claim->role,
            'Name given: '.$this->clip($claim->name, 120),
            'Contact email: '.$claim->email,
            'Account: @'.($user?->username ?? 'deleted').', name "'.($user?->fullname ?? '').'", email '.($user?->email ?? 'unknown')
                .', joined '.($user?->created_at?->toDateString() ?? 'unknown'),
            'Proof links:',
            collect($claim->links)->map(fn ($link) => '- '.$link)->join("\n"),
            'Their note: '.$this->clip($claim->message),
            'Supporting document attached: '.($claim->document ? 'yes (you cannot read it)' : 'no'),
            "Other people also claiming this artist right now: {$others}",
        ]);
    }

    public function approve(?string $note): void
    {
        $this->claims->approve($this->subject, null, $note);
    }

    public function reject(string $note): void
    {
        $this->claims->reject($this->subject, null, $note);
    }
}
