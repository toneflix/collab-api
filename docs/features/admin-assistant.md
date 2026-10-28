# Admin assistant

The admin assistant is a chat at **Admin → Assistant** (`/admin/assistant`) where an admin asks for moderation work in plain words: "suspend @jane for a week for spam", "what's in the moderation queue?", "take down track 412 for copyright". It is not autonomous. It only works when the admin writes, and only for that admin.

## What it can do

The assistant works through tools, and each tool calls the same service as the admin pages, as the signed-in admin:

| Area | Tools | Goes through |
| --- | --- | --- |
| Accounts | search and open accounts, suspend or ban, lift a suspension, verified badge, roles, plan, balance, AI credits, profile edits | `UserManager` and `UserDirectory` |
| Content | moderation queue, search content, open content, take down, delete, close reports, list takedowns, restore, uphold an appeal | `Moderation`, `ModerationQueue`, `Reportables` |
| Reviews | releases in review, approve or reject a release, artist claims, approve or decline a claim | `ReleaseVersions`, `ArtistClaims` |

An admin is only offered the tools their permissions allow (`manage-users` for accounts, plus `manage-plans`, `manage-payouts` or `manage-admins` where the admin pages need them, and `manage-content` for content and reviews). Every change also runs the same checks as the admin pages, such as `UserManager::authorize`, so the assistant can never do more than the admin asking. Admins need `manage-users` or `manage-content` to open it.

Search results come 10 a page, and the assistant asks for the next page when it needs it.

## Confirmations

Changes that take something away or are hard to undo don't run when the assistant asks for them. The chat shows a card with a summary, like "Suspend @jane (#12) for 7 days: spam", and the change only runs when the admin presses **Confirm**:

- suspending or banning, role changes, plan changes, balance changes, profile edits, taking the verified badge away, taking credits back
- takedowns, closing reports, upholding an appeal, rejecting a release, approving or declining an artist claim

Looking things up, lifting a suspension, giving the verified badge, granting credits, restoring content and approving a release run at once.

Deleting content asks for more on the card: the admin types the item's name and a code from their authenticator app, the same as on the moderation page. The model never sees what they type, and a wrong name or code leaves the card waiting so they can try again. Admins without two-factor sign-in are told to turn it on first. Tools ask for these through `AssistantTool::asks()` (stored on the action) and get them in `confirmWith()`.

When the admin confirms, the tool checks the admin's rights again, so a permission removed in between stops it. A card expires after an hour. Cancelled, expired and failed cards stay in the chat with their status.

## Audit trail

Account changes are recorded in the account's admin history like any other, with the admin's name and `details.via = assistant` ("@admin via Assistant" on the user page). Takedowns and reviews record the admin as the moderator, as on the moderation page. The chat itself (`assistant_chats`, `assistant_messages`) and every proposed change with its outcome (`assistant_actions`) are kept too.

## Model

The language model runs on Replicate or Cloudflare Workers AI, picked under **Admin → AI tools → Admin assistant** (`ai_assistant_engine`). The model is set in `.env`: `REPLICATE_ASSISTANT_MODEL` (default `anthropic/claude-4.5-sonnet`) or `CLOUDFLARE_ASSISTANT_MODEL` (default `anthropic/claude-haiku-4.5`). The assistant answers in a queued job (`RespondToAdmin`), so a queue worker must run. The page follows along over realtime on the admin's `user.{id}` channel (`assistant.chat`), and polls when realtime is off.

Answers don't cost AI credits.
