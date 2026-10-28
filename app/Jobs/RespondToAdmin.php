<?php

namespace App\Jobs;

use App\Models\AssistantChat;
use App\Services\Assistant\AdminAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lets the admin assistant answer the latest message in a chat.
 */
class RespondToAdmin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $chatId) {}

    public function handle(AdminAssistant $assistant): void
    {
        $chat = AssistantChat::query()
            ->find($this->chatId);

        if ($chat) {
            $assistant->respond($chat);
        }
    }

    /**
     * A job that died, for example on a timeout, must not leave the chat
     * waiting for an answer.
     */
    public function failed(?\Throwable $e): void
    {
        AssistantChat::query()
            ->whereKey($this->chatId)
            ->where('status', AssistantChat::THINKING)
            ->update([
                'status' => AssistantChat::FAILED,
                'error' => 'The assistant couldn\'t answer. Try again.',
                'updated_at' => now(),
            ]);
    }
}
