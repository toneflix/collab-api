# Moderation

Moderation covers reports from people, copied-audio matches and lyrics found automatically, takedowns, and appeals.

## Reports

Anyone signed in can report a release, track, composition, project, sound or comment from its menu. Reports are stored in the `safeties` table with `type = report` and wait in the admin queue at `/admin/moderation` (needs `manage-content`, created by `php artisan app:sync-roles`).

From the queue an admin can:

- **Dismiss** the report, leaving the content up.
- **Take down** the content with a reason. Taking a release down also takes its tracks down.
- **Resolve** it without a takedown, when it was handled another way.
- **Delete** the content for good. See below.

## Deleting content

Admins with `manage-content` can delete a release, track, composition, project, sound or comment from the queue or the takedowns list (`DELETE /api/admin/moderation/items/{type}/{id}`). Accounts are not deleted here; they are suspended or banned from Users.

Deleting asks for two things:

- the item's name typed out (`confirm_name`): its title, or `comment #12` for a comment. Queue items carry it as `deleteName`;
- a current code from the admin's authenticator app (`code`). Admins without [two-factor sign-in](./auth#two-factor-sign-in) can't delete.

Pending reports on the item close, the owner and reporters are notified, and the owner's audit trail on their admin user page gets a `content.deleted` entry with the title, reason and note. Deleting a release deletes its tracks, and deleting a project deletes its tracks, comments and collaborators, the same as when owners delete their own.

## Takedowns and appeals

Taken-down content is hidden from everyone except its owner. Owners see a banner on it and a list at `/studio/takedowns`, and can appeal each takedown once. Admins **restore** the content or **uphold** the takedown. Owners are notified at each step.

Takedowns are in the `takedowns` table. In code, models that can be taken down use the `TakesDown` trait, which adds a `taken_down_at` column and the `HideTakenDown` global scope. It is used on `Release`, `Track`, `Song`, `Project`, `Sound` and `Comment`. A taken-down project is hidden from its collaborators too.

## Copied-audio detection

Every uploaded track is checked by the queued `FingerprintTrack` job:

1. It stores the file's SHA-256 hash, which catches exact copies.
2. When FFmpeg has Chromaprint, it also stores Chromaprint codes (`audio_fingerprints` and `audio_fingerprint_keys`), which catch re-encoded copies.
3. A track that matches an earlier track by **another owner** at 85% or more is added to the queue as a `match`, with the original linked, so an admin can compare them.

Matches never take anything down on their own. Without Chromaprint only exact copies are caught; see [FFmpeg and Chromaprint](../guide/ffmpeg).

## Lyrics check

When a released track's lyrics are saved, whether written out by Whisper or by the creator, they are checked against two word lists in `config/lyrics.php`, plus words admins add on `/admin/ai`:

- **Explicit words** mark the track explicit. Nothing goes to the queue.
- **Words to review** add the track to the queue as a `lyrics` flag with the words found. A moderator who dismisses it won't see the same words flagged again.

See [Lyrics](./ai#lyrics).

## Artist profiles and claims

An artist's profile (name, bio, photo and cover photo) can be edited by the account that manages the artist and by admins with `manage-content`. Only admins can mark an artist verified. `App\Policies\ArtistPolicy` decides both.

Artists that no account manages (`artists.user_id` is empty, such as imported catalogue) show a **Claim this artist** button. The claimant says how they are connected (artist, manager, label or other), gives contact details, one to five links that prove it, a note, and an optional document (PDF, JPG or PNG up to 10 MB). Documents are stored privately on the default disk under `artist-claims/` and only moderators can download them.

Claims wait on the **Artist claims** tab of `/admin/moderation`. Approving one:

- links the artist to the claimant's account and marks it verified, so they can edit it and pick it on their releases,
- moves the artist's releases that no account owns to the claimant, so they manage them from Studio (releases other creators uploaded stay with them),
- declines the other pending claims on the artist.

Declining needs a note, which the claimant sees. Claimants are notified either way. `App\Services\Artists\ArtistClaims` makes every change to claims.

## AI moderator

The AI moderator reviews artist claims and releases waiting for approval. It runs on a Claude model through Replicate (`REPLICATE_MODERATOR_MODEL`), with the same `REPLICATE_API_TOKEN` as the AI tools, and needs a queue worker.

- **Sent by an admin.** Each claim and release in the queue has a **Send to AI moderator** button. These reviews always end in a decision: approved, or declined or sent back with a note to the submitter. If the model can't decide, it declines, since that grants nothing.
- **Sent automatically.** On `/admin/ai`, admins can send every new artist claim, every release waiting for approval, or both to the moderator. An automatic review only acts when the moderator is at least as confident as **Confidence Needed** (80 by default). Otherwise the item stays in the queue, flagged for a person with the moderator's reasoning.

The moderator sees the case as text: for claims, the artist, its latest releases and labels, the claimant's details, links and note (it can't open the links or the document); for releases, the details, tracks, lyrics, open copy flags and the creator's takedowns, plus public artwork. Every review is kept in `ai_reviews` with its decision, confidence and reasoning, and decided items show "AI moderator" as the reviewer. A review that fails, for example when Replicate is down, can be sent again.

## In code

- `App\Services\Moderation\Moderation` is the only place moderation state changes: `report`, `flagMatches`, `flagLyrics`, `takeDown`, `delete`, `dismiss`, `resolve`, `appeal`, `restore`, `uphold`.
- `ModerationQueue` builds the admin views.
- `Reportables` maps API type names to models. Reportable models implement `App\Contracts\Reportable` or `CanBeTakenDown`.
- `App\Services\Moderation\Ai\AiModerator` sends and runs AI reviews. `ReviewCases` builds a case (`ArtistClaimCase`, `ReleaseCase`) that describes the item and carries out the decision through `ArtistClaims` or `ReleaseVersions`. The model sits behind `ModeratorModel`.
- In the app: `ReportDialog`, `/admin/moderation` and `/studio/takedowns`.
