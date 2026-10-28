<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HttpStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssistantActionResource;
use App\Http\Resources\AssistantChatCollection;
use App\Http\Resources\AssistantChatResource;
use App\Http\Resources\AssistantMessageCollection;
use App\Http\Resources\AssistantMessageResource;
use App\Models\AssistantAction;
use App\Models\AssistantChat;
use App\Services\Assistant\AdminAssistant;
use Closure;
use Illuminate\Http\Request;

/**
 * The admin assistant chat: the admin's chats, their messages, and the
 * actions the admin confirms or cancels.
 */
class AssistantController extends Controller
{
    public function __construct(protected AdminAssistant $assistant)
    {
        parent::__construct();

        $this->middleware(function (Request $request, Closure $next) {
            abort_unless(
                $this->assistant->allows($request->user()),
                HttpStatus::FORBIDDEN->value,
                'You do not have permission to use the admin assistant.'
            );

            return $next($request);
        });
    }

    /**
     * The admin's chats, latest activity first.
     */
    public function index(Request $request)
    {
        $chats = $request
            ->user()
            ->assistantChats()
            ->latest('updated_at')
            ->latest('id')
            ->paginate($request->perPage(20, 50));

        return (new AssistantChatCollection($chats))
            ->additional([
                'available' => $this->assistant->available(),
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * Start a chat with a first message.
     */
    public function store(Request $request)
    {
        $valid = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $chat = $this->assistant->start($request->user(), $valid['message']);

        return (new AssistantChatResource($chat))
            ->additional([
                'message' => 'Sent.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function show(Request $request, int $chat)
    {
        $chat = $this->chat($request, $chat);

        return (new AssistantChatResource($chat))
            ->additional([
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    /**
     * The chat's messages, newest first, with the chat beside them so the
     * app knows when the assistant has answered.
     */
    public function messages(Request $request, int $chat)
    {
        $chat = $this->chat($request, $chat);

        $messages = $chat
            ->messages()
            ->with('action')
            ->latest('id')
            ->cursorPaginate($request->perPage(30, 100));

        $resource = new AssistantChatResource($chat);

        return (new AssistantMessageCollection($messages))
            ->additional([
                'chat' => $resource,
                'message' => HttpStatus::message(HttpStatus::OK),
                'status' => 'success',
                'status_code' => HttpStatus::OK,
            ]);
    }

    public function post(Request $request, int $chat)
    {
        $chat = $this->chat($request, $chat);
        $valid = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $message = $this->assistant->post($chat, $valid['message']);

        return (new AssistantMessageResource($message))
            ->additional([
                'message' => 'Sent.',
                'status' => 'success',
                'status_code' => HttpStatus::CREATED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::CREATED->value);
    }

    public function confirm(Request $request, AssistantAction $action)
    {
        $action = $this->assistant->confirm($action, $request->user());

        return $this->decided($action, $action->status === AssistantAction::DONE ? 'Done.' : 'It couldn\'t be done.');
    }

    public function cancel(Request $request, AssistantAction $action)
    {
        $action = $this->assistant->cancel($action, $request->user());

        return $this->decided($action, 'Cancelled.');
    }

    protected function decided(AssistantAction $action, string $message)
    {
        return (new AssistantActionResource($action))
            ->additional([
                'message' => $message,
                'status' => 'success',
                'status_code' => HttpStatus::ACCEPTED,
            ])
            ->response()
            ->setStatusCode(HttpStatus::ACCEPTED->value);
    }

    /**
     * One of the signed-in admin's own chats.
     */
    protected function chat(Request $request, int $id): AssistantChat
    {
        return $request
            ->user()
            ->assistantChats()
            ->findOrFail($id);
    }
}
