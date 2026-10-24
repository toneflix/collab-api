# Conversations

People can talk about a project, a project track, a published track or a release. Every conversation hangs off one of those, and the realtime channel is `conversation.{type}.{id}` with type `project`, `project-track`, `track` or `release`.

## In the app

- A **dock** at the bottom right of every page (`ConversationDock`) lists open conversations and stays open while people move around.
- The **`/messages`** page shows every conversation someone is part of, with unread counts.
- Messages can be text, a **voice note** recorded in the browser, or a **region message** in the studio that points at a stretch of a stem. Opening a region message highlights that region in the editor.
- Replies are threaded under a message.

## Notifications

New messages are sent live on `user.{id}` to everyone in the conversation. If someone has not read them after `NOTIFY_MESSAGE_DELAY` minutes (5 by default), they get one email and push notice for that conversation. A busy chat still sends at most one notice per delay. People can turn these off under the `messages` category on `/account/notifications`.

## In code

- API routes: `/api/conversations`, in `routes/api/conversations.php`.
- `App\Services\Conversations\Conversations` finds conversations and shapes messages. Messages are comments (`beyondcode/laravel-comments`) with `root_type` and `root_id` pointing at what the conversation is about.
- Read markers are in `conversation_reads`.
- Voice notes and regions live in `comments.meta` (`voice` and `region`). Voice files are in the `voice_notes` file collection.
- In the app: `src/stores/conversation-store.ts`, `ConversationDock` (mounted in `MainLayout`) and the `/messages` page.
