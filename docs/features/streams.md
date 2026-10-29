# Stream earnings

Creators whose plan includes **Earn from streams** (`earn_streams`, on for Premium) get a share of a monthly pool for signed-in plays of their released tracks.

## Paid streams

The app reports a play with `POST /api/tracks/{track}/plays` (`seconds`) once a signed-in listener has played a track for `stream_min_seconds`. Each listener has one row per track and day in `track_plays`, which keeps the longest play that day. The row is **paid** when:

- the play lasted at least `stream_min_seconds` (30 by default),
- the listener isn't the track's owner or a collaborator on its studio project,
- the owner's plan has `earn_streams` when the play happens,
- the listener has given fewer than `stream_daily_cap` (200) paid streams that day.

So a listener pays each track at most once a day. Guests' plays aren't recorded. A track's `streams` count is its number of play rows, paid or not.

## The monthly pool

On the 1st at 03:00, `earnings:settle-streams` pays out the previous month (run it with `--month=YYYY-MM` for another past month, or use **Payouts > Streams** in the admin):

1. Revenue is every completed `premium` payment paid that month.
2. The pool is `stream_pool_share` percent of it (30 by default).
3. Each paid stream is weighted by the track's [rank](./moderation#track-ranking): `stream_weight_collaboration` (1.5), `stream_weight_recorded` (1) or `stream_weight_agentic` (0.5). Unranked tracks count as agentic until ranked.
4. One weighted stream pays `pool / total weighted streams`. Each owner gets one `transactions` row with purpose `streams`, `recipient_share` set and `amount` 0, linked to the month in `stream_periods`.

Settling is safe to run twice: a settled month is left alone and owners already paid are skipped. Earnings then clear and are withdrawn like sales (see [Payouts](./payouts)), and owners get a sales notification.

Creators see this month's paid streams and an estimate at the last month's rate on **Earnings** (`GET /api/account/earnings/streams`). Admins see each settled month and the month in progress on **Payouts > Streams** (`GET /api/admin/payouts/streams`, needs `manage-payouts`).

## In code

- `App\Services\Streams\StreamPlays` records plays and decides which are paid.
- `StreamEarnings` previews and settles months; `StreamRules` reads the settings.
- `TrackPlay` and `StreamPeriod` are the models. Popularity scopes (`popularPlayed*`, trending artists, recommendations) read `track_plays`.
