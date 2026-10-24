# Moderation

Moderation covers reports from people, copied-audio matches found automatically, takedowns, and appeals.

## Reports

Anyone signed in can report a release, track, sound or comment from its menu. Reports are stored in the `safeties` table with `type = report` and wait in the admin queue at `/admin/moderation` (needs `manage-content`, created by `php artisan app:sync-roles`).

From the queue an admin can:

- **Dismiss** the report, leaving the content up.
- **Take down** the content with a reason. Taking a release down also takes its tracks down.
- **Resolve** it without a takedown, when it was handled another way.

## Takedowns and appeals

Taken-down content is hidden from everyone except its owner. Owners see a banner on it and a list at `/studio/takedowns`, and can appeal each takedown once. Admins **restore** the content or **uphold** the takedown. Owners are notified at each step.

Takedowns are in the `takedowns` table. In code, models that can be taken down use the `TakesDown` trait, which adds a `taken_down_at` column and the `HideTakenDown` global scope. It is used on `Release`, `Track`, `Sound` and `Comment`.

## Copied-audio detection

Every uploaded track is checked by the queued `FingerprintTrack` job:

1. It stores the file's SHA-256 hash, which catches exact copies.
2. When FFmpeg has Chromaprint, it also stores Chromaprint codes (`audio_fingerprints` and `audio_fingerprint_keys`), which catch re-encoded copies.
3. A track that matches an earlier track by **another owner** at 85% or more is added to the queue as a `match`, with the original linked, so an admin can compare them.

Matches never take anything down on their own. Without Chromaprint only exact copies are caught; see [FFmpeg and Chromaprint](../guide/ffmpeg).

## In code

- `App\Services\Moderation\Moderation` is the only place moderation state changes: `report`, `flagMatches`, `takeDown`, `dismiss`, `resolve`, `appeal`, `restore`, `uphold`.
- `ModerationQueue` builds the admin views.
- `Reportables` maps API type names to models. Reportable models implement `App\Contracts\Reportable` or `CanBeTakenDown`.
- In the app: `ReportDialog`, `/admin/moderation` and `/studio/takedowns`.
