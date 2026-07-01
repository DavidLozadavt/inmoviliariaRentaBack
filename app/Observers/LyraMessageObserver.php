<?php

namespace App\Observers;

use App\Models\LyraMessage;
use App\Models\LyraAdminAlert;
use Carbon\Carbon;

class LyraMessageObserver
{
    public function created(LyraMessage $message): void
    {
        if ($message->role === 'user' && $message->intent === 'fuera_de_contexto') {
            $this->checkHighOutOfContext($message);
        }
    }

    private function checkHighOutOfContext(LyraMessage $message): void
    {
        // Count out of context messages in last 10 minutes
        $count = LyraMessage::where('role', 'user')
            ->where('intent', 'fuera_de_contexto')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($count >= 5) {
            // Check if alert already exists recently to avoid spam
            $exists = LyraAdminAlert::where('type', 'high_out_of_context')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->exists();

            if (!$exists) {
                LyraAdminAlert::create([
                    'type'    => 'high_out_of_context',
                    'message' => "Alerta: Se han detectado {$count} mensajes fuera de contexto en los últimos 10 minutos.",
                    'payload' => [
                        'count'      => $count,
                        'session_id' => $message->lyra_session_id
                    ]
                ]);
            }
        }
    }
}
