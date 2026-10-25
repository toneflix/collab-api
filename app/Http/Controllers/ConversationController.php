<?php

namespace App\Http\Controllers;

use App\Enums\HttpStatus;
use App\Helpers\Providers;
use App\Http\Resources\ConversationMessageCollection;
use App\Http\Resources\ConversationMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectTrack;
use App\Models\Track;
use App\Services\Conversations\Conversations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Toneflix\LaravelFileable\Media;

/**
 * Conversations as a chat: the list of a user's conversations, and each
 * conversation's messages, newest page first.
 */
class ConversationController extends Controller
{
    /**
     * The signed-in user's conversations, most recent first.
     */
    public function index(Request $request)
    {
        $request->validate(['limit' => ['nullable', 'integer', 'between:1,50']]);

        $list = Conversations::forUser($request->user(), (int) $request->input('limit', 50));

        return ConversationResource::collection($list)->additional([
            'unread' => $list->getCollection()->sum('unread_count'),
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * What one conversation is about.
     */
    public function show(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());

        return (new ConversationResource($root))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * A page of messages, oldest first. Without a cursor it's the newest
     * page; pass `meta.next_cursor` as `cursor` for the page above it.
     */
    public function messages(Request $request, string $type, string $id)
    {
        $request->validate(['limit' => ['nullable', 'integer', 'between:1,100']]);

        $root = Conversations::find($type, $id, $request->user());

        $messages = Conversations::messagesPage($root, (int) $request->input('limit', 30));

        return (new ConversationMessageCollection($messages))->additional([
            'message' => HttpStatus::message(HttpStatus::OK),
            'status' => 'success',
            'status_code' => HttpStatus::OK,
        ]);
    }

    /**
     * Send a message: text, a voice note, or both. It can reply to another
     * message, and on a project track it can point at part of the track.
     */
    public function store(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());

        if (is_string($request->input('waveform'))) {
            $request->merge(['waveform' => json_decode($request->input('waveform'), true)]);
        }

        $valid = $request->validate([
            'body' => ['nullable', 'required_without:voice', 'string', 'max:5000'],
            'reply_to' => ['nullable', 'integer'],
            'voice' => [
                'nullable',
                'file',
                'max:10240',
                'mimetypes:audio/webm,video/webm,audio/ogg,audio/opus,audio/mp4,video/mp4,audio/x-m4a,audio/aac,audio/mpeg,audio/wav,audio/x-wav',
            ],
            'duration' => ['nullable', 'required_with:voice', 'numeric', 'min:0', 'max:600'],
            'waveform' => ['nullable', 'array', 'max:120'],
            'waveform.*' => ['numeric', 'min:0', 'max:1'],
            'region' => ['nullable', 'array'],
            'region.start' => ['required_with:region', 'numeric', 'min:0'],
            'region.end' => ['required_with:region', 'numeric', 'gt:region.start'],
            'region.stem_id' => ['nullable', 'integer'],
        ], [
            'body.required_without' => 'Write a message or record a voice note.',
            'voice.max' => 'Voice notes can be up to 10 MB.',
            'voice.mimetypes' => 'That recording format isn\'t supported.',
        ], ['body' => 'message']);

        $target = $root;
        if (! empty($valid['reply_to'])) {
            $target = Conversations::messages($root)->find($valid['reply_to']);
            abort_unless($target, HttpStatus::UNPROCESSABLE_ENTITY->value, 'The message you replied to is gone.');
        }

        $meta = array_filter([
            'region' => isset($valid['region']) ? $this->region($root, $valid['region']) : null,
            'voice' => $request->hasFile('voice') ? $this->voice($request, $valid) : null,
        ]);

        /** @var Comment $comment */
        $comment = $target->commentAsUser($request->user(), trim((string) ($valid['body'] ?? '')));
        if ($meta) {
            $comment->meta = $meta;
            $comment->save();
        }

        Conversations::markRead($root, $request->user());
        Conversations::posted($comment);

        return (new ConversationMessageResource($comment))->additional([
            'message' => 'Message sent.',
            'status' => 'success',
            'status_code' => HttpStatus::CREATED,
        ])->response()->setStatusCode(HttpStatus::CREATED->value);
    }

    /**
     * The part of the track a message points at. Only project tracks have
     * an editor to point into.
     *
     * @param  array{start: float|string, end: float|string, stem_id?: int|string|null}  $region
     * @return array<string, mixed>
     */
    protected function region(Model $root, array $region): array
    {
        abort_unless(
            $root instanceof ProjectTrack,
            HttpStatus::UNPROCESSABLE_ENTITY->value,
            'Only messages on a project track can point at part of it.'
        );

        $stem = null;
        if (! empty($region['stem_id'])) {
            $stem = $root->stems()->find($region['stem_id']);
            abort_unless($stem, HttpStatus::UNPROCESSABLE_ENTITY->value, 'That stem isn\'t on this track.');
        }

        return [
            'start' => round((float) $region['start'], 3),
            'end' => round((float) $region['end'], 3),
            'stemId' => $stem?->id,
            'stemName' => $stem ? ($stem->title ?: ($stem->meta['name'] ?? null)) : null,
            'trackId' => $root->id,
            'projectId' => $root->project_id,
        ];
    }

    /**
     * Store an uploaded voice note.
     *
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    protected function voice(Request $request, array $valid): array
    {
        $file = $request->file('voice');
        $saved = (new Media)->save('voice_notes', $file);

        return [
            'file' => is_array($saved) ? $saved['new_name'] : $saved,
            'duration' => round((float) $valid['duration'], 2),
            'waveform' => collect($valid['waveform'] ?? [])->map(fn ($v) => round((float) $v, 2))->values()->all(),
            'mime' => $file->getMimeType(),
        ];
    }

    /**
     * Delete a message. Authors can delete their own; owners anything.
     */
    public function destroy(Request $request, string $type, string $id, string $message)
    {
        $root = Conversations::find($type, $id, $request->user());

        /** @var Comment|null $comment */
        $comment = Conversations::messages($root)->find($message);
        abort_unless($comment, HttpStatus::NOT_FOUND->value, 'This message no longer exists.');
        abort_unless(
            (int) $comment->user_id === (int) $request->user()->id || Conversations::ownedBy($root, $request->user()),
            HttpStatus::FORBIDDEN->value,
            'You can only delete your own messages.'
        );

        Conversations::broadcast($comment, 'comment.deleted');
        Conversations::forgetVoice($comment);
        $comment->delete();

        return Providers::response()->success([
            'data' => ['id' => (int) $message],
            'message' => 'Message deleted.',
        ], HttpStatus::ACCEPTED);
    }

    /**
     * Mark the conversation read up to now.
     */
    public function read(Request $request, string $type, string $id)
    {
        $root = Conversations::find($type, $id, $request->user());
        Conversations::markRead($root, $request->user());

        return Providers::response()->success([
            'data' => ['unread' => 0],
            'message' => HttpStatus::message(HttpStatus::OK),
        ]);
    }
}
