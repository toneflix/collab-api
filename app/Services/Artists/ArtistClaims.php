<?php

namespace App\Services\Artists;

use App\Events\AwaitingReview;
use App\Models\Artist;
use App\Models\ArtistClaim;
use App\Models\Release;
use App\Models\User;
use App\Notifications\ModerationNotice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Claims on artists no account manages yet. Approving a claim links the
 * artist to the claimant's account and hands them its unowned catalogue.
 */
class ArtistClaims
{
    /** Where proof documents are kept, on the default (private) disk */
    public const DOCUMENTS = 'artist-claims';

    /**
     * File a claim, or update the user's pending one.
     *
     * @param  array{role: string, name: string, email: string, links: array<int, string>, message: string}  $data
     */
    public function submit(User $user, Artist $artist, array $data, ?UploadedFile $document = null): ArtistClaim
    {
        $claim = $artist->claims()->pending()->where('user_id', $user->id)->first()
            ?? $artist->claims()->make(['user_id' => $user->id]);

        if ($document) {
            $this->deleteDocument($claim);
            $claim->document = $document->store(self::DOCUMENTS, $this->disk());
        }

        $claim->fill([
            ...$data,
            'links' => array_values(array_unique($data['links'])),
        ])->save();

        AwaitingReview::dispatch($claim);

        return $claim;
    }

    /**
     * Link the artist to the claimant. Releases of the artist that no account
     * owns move to them, and other pending claims on the artist are declined.
     */
    public function approve(ArtistClaim $claim, ?User $admin, ?string $note = null): ArtistClaim
    {
        $this->ensurePending($claim);

        DB::transaction(function () use ($claim, $admin, $note) {
            $artist = Artist::query()->lockForUpdate()->findOrFail($claim->artist_id);

            if (! $artist->isClaimable()) {
                throw ValidationException::withMessages(['claim' => 'This artist is already managed by an account.']);
            }

            $artist->forceFill(['user_id' => $claim->user_id, 'verified' => true])->saveQuietly();

            Release::query()
                ->withoutGlobalScopes()
                ->where('artist_id', $artist->id)
                ->whereNull('user_id')
                ->update(['user_id' => $claim->user_id]);

            $this->review($claim, ArtistClaim::APPROVED, $admin, $note);
        });

        $artist = $claim->artist;

        $this->notify($claim, 'Your artist claim was approved', trim(
            "You now manage {$artist->name} on Toneflix. You can edit the profile and manage the catalogue from Studio. " . ($note ?? '')
        ));

        $this->declineOthers($claim, $admin);

        return $claim;
    }

    public function reject(ArtistClaim $claim, ?User $admin, string $note): ArtistClaim
    {
        $this->ensurePending($claim);

        $this->review($claim, ArtistClaim::REJECTED, $admin, $note);

        $this->notify($claim, 'Your artist claim was declined', "We couldn't confirm your claim on {$claim->artist->name}: {$note}");

        return $claim;
    }

    /**
     * Claims for moderators, oldest pending first.
     */
    public function queue(?string $status = ArtistClaim::PENDING, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        return ArtistClaim::query()
            ->when($status, fn(Builder $q, string $status) => $q->where('status', $status))
            ->when($search, fn(Builder $q, string $search) => $q->where(fn(Builder $q) => $q
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('email', "%{$search}%")
                ->orWhereHas('artist', fn(Builder $q) => $q
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('username', "%{$search}%"))
                ->orWhereHas('user', fn(Builder $q) => $q
                    ->whereLike('username', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%"))))
            ->with([
                'user',
                'reviewer',
                'latestAiReview',
                'artist' => fn($q) => $q->withCount([
                    'releases as unowned_releases_count' => fn(Builder $q) => $q->withoutGlobalScopes()->whereNull('user_id'),
                ]),
            ])
            ->when(
                $status === ArtistClaim::PENDING,
                fn(Builder $q) => $q->oldest('id'),
                fn(Builder $q) => $q->latest('id'),
            )
            ->paginate($perPage);
    }

    public function pendingCount(): int
    {
        return ArtistClaim::query()->pending()->count();
    }

    /**
     * The user's latest claim on the artist.
     */
    public function latestFor(?User $user, Artist $artist): ?ArtistClaim
    {
        return $user ? $artist->claims()->where('user_id', $user->id)->latest('id')->first() : null;
    }

    public function downloadDocument(ArtistClaim $claim): StreamedResponse
    {
        abort_unless($claim->document && Storage::disk($this->disk())->exists($claim->document), 404, 'This claim has no document.');

        return Storage::disk($this->disk())->download($claim->document, 'claim-' . $claim->id . '-proof.' . pathinfo($claim->document, PATHINFO_EXTENSION));
    }

    protected function review(ArtistClaim $claim, string $status, ?User $admin, ?string $note): void
    {
        $claim->forceFill([
            'status' => $status,
            'reviewed_by' => $admin?->id,
            'review_note' => $note,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * Decline the other pending claims on an artist that was just claimed.
     */
    protected function declineOthers(ArtistClaim $approved, ?User $admin): void
    {
        $note = 'Another account was confirmed as this artist\'s manager.';

        ArtistClaim::query()
            ->pending()
            ->where('artist_id', $approved->artist_id)
            ->with('artist')
            ->chunkById(100, function (Collection $claims) use ($admin, $note) {
                foreach ($claims as $claim) {
                    $this->reject($claim, $admin, $note);
                }
            });
    }

    protected function ensurePending(ArtistClaim $claim): void
    {
        if (! $claim->isPending()) {
            throw ValidationException::withMessages(['claim' => 'This claim was already reviewed.']);
        }
    }

    protected function notify(ArtistClaim $claim, string $title, string $message): void
    {
        $claim->user?->notify(new ModerationNotice($title, $message, [
            'artist_claim_id' => $claim->id,
            'artist' => $claim->artist->username,
            'status' => $claim->status,
        ]));
    }

    protected function deleteDocument(ArtistClaim $claim): void
    {
        if ($claim->document) {
            Storage::disk($this->disk())->delete($claim->document);
        }
    }

    protected function disk(): string
    {
        return (string) config('filesystems.default');
    }
}
