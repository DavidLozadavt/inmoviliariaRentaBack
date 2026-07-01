<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class LyraNewSession implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public array $session;

    public function __construct(array $session)
    {
        $this->session = $session;
    }

    public function broadcastOn(): Channel
    {
        // MISMO canal que escucha el frontend
        return new Channel('lyra.sessions');
    }

    public function broadcastAs(): string
    {
        // El frontend escucha '.session.new'
        // Laravel agrega el punto automáticamente si no lo pones
        return 'session.new';
    }

    public function broadcastWith(): array
    {
        return ['session' => $this->session];
    }
}
