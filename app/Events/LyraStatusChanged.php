<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow para que no necesite queue
class LyraStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $status;
    public string $message;

    public function __construct(string $status, string $message = '')
    {
        $this->status  = $status; // 'online' | 'maintenance'
        $this->message = $message;
    }

    /**
     * Canal público — cualquier visitante del sitio lo recibe
     * sin necesidad de estar autenticado
     */
    public function broadcastOn(): Channel
    {
        return new Channel('lyra.status');
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'status'  => $this->status,
            'message' => $this->message,
        ];
    }
}
