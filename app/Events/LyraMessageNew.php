<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class LyraMessageNew implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $sessionId;
    public array  $message;

    public function __construct(string $sessionId, array $message)
    {
        $this->sessionId = $sessionId;
        $this->message   = $message;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('lyra.sessions');
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    public function broadcastWith(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'message'   => $this->message,
        ];
    }
}
