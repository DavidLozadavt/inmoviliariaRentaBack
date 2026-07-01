<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class LyraSessionDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('lyra.sessions');
    }

    public function broadcastAs(): string
    {
        return 'session.deleted';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->id];
    }
}
