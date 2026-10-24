<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A realtime event sent straight away (not queued), so collaborators see
 * changes as they happen.
 */
class RealtimeMessage implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  array<int, Channel>  $channels
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $channels,
        public string $event,
        public array $data = [],
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->data;
    }
}
