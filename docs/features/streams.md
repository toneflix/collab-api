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

1. Revenue is the Premium paid for that month. A payment for several months (a yearly plan, or several cycles at once) is spread over the period it bought by days, so each month gets its part. Periods admins give for free don't count.
2. The pool is `stream_pool_share` percent of it (30 by default).
3. Each paid stream is weighted by the track's [rank](./moderation#track-ranking): `stream_weight_collaboration` (1.5), `stream_weight_recorded` (1) or `stream_weight_agentic` (0.5). Unranked tracks count as agentic until ranked.
4. Before paying, the month's [stream checks](#stream-checks) run. Held streams are left out.
5. One weighted stream pays `pool / total weighted streams`. Each owner gets one `transactions` row with purpose `streams`, `recipient_share` set and `amount` 0, linked to the month in `stream_periods`.

Settling is safe to run twice: a settled month is left alone and owners already paid are skipped. Earnings then clear and are withdrawn like sales (see [Payouts](./payouts)), and owners get a sales notification.

Admins see each settled month and the month in progress on **Payouts > Streams** (`GET /api/admin/payouts/streams`, needs `manage-payouts`).

## Stream checks

A listener who gives one creator at least `stream_check_plays` (100) paid streams in a month, making up at least `stream_check_share` (80) percent of their paid streams that month, gets a **stream check**. `earnings:check-streams` looks for them every day at 02:30 and again when a month is settled.

- While a check is pending, that listener's paid streams to that creator in that month are **held** (`track_plays.held`). New paid plays between them are held as they come in. Held streams are left out of the payout.
- Admins review checks on **Payouts > Stream checks** (`GET /api/admin/payouts/stream-checks?status=pending|cleared|confirmed`, `PUT /api/admin/payouts/stream-checks/{check}` with `decision` `clear` or `confirm`). Each check shows the listener, the creator, the share and the tracks played most.
- **Clear** releases the streams. If the month was already settled, the creator gets them at that month's rate as an extra `streams` earning (`stream_checks.top_up`).
- **Confirm** keeps the streams out of every payout.
- A reviewed check is not opened again for the same month. Decisions go to the listener's admin history.

## Stats for creators

- **Earnings** (`GET /api/account/earnings/streams`) shows this month's paid and held streams, an estimate at the last month's rate, total earned and the last twelve months.
- `GET /api/account/earnings/streams/tracks?month=YYYY-MM` lists the creator's tracks with plays, paid and held streams and earnings for a month, best first. `release_id` or `project_id` narrows it to an album or project.
- `GET /api/account/stats/{track|album|project}/{id}` gives one item's totals (plays, listeners, and paid streams, held streams and earnings over twelve months) and its months. Only the owner can see them.

Earnings for a settled month use that month's rate and weights. The month in progress is estimated at the last settled rate.

## In code

- `App\Services\Streams\StreamPlays` records plays and decides which are paid.
- `StreamEarnings` previews and settles months; `StreamRules` reads the settings.
- `StreamChecks` opens, clears and confirms checks; `StreamStats` builds the monthly stats.
- `TrackPlay`, `StreamPeriod` and `StreamCheck` are the models. Popularity scopes (`popularPlayed*`, trending artists, recommendations) read `track_plays`.
