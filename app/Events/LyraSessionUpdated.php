<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class LyraSessionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string  $id;
    public string  $status;
    public ?string $lastIntent;
    public ?string $city;

    public function __construct(string $id, string $status, ?string $lastIntent = null, ?string $city = null)
    {
        $this->id         = $id;
        $this->status     = $status;
        $this->lastIntent = $lastIntent;
        $this->city       = $city;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('lyra.sessions');
    }

    public function broadcastAs(): string
    {
        return 'session.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->id,
            'status'     => $this->status,
            'lastIntent' => $this->lastIntent,
            'city'       => $this->city,
        ];
    }
}
