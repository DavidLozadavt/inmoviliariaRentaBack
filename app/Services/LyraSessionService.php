<?php

namespace App\Services;

use App\Models\LyraSession;
use App\Models\LyraMessage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LyraSessionService
{
    public function getSessions(array $filters): array
    {
        $query = LyraSession::withCount('messages')->orderByDesc('updated_at');

        if (!empty($filters['intent'])) {
            $query->where('last_intent', $filters['intent']);
        }
        if (!empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }
        if (!empty($filters['dateFrom'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['dateFrom']));
        }
        if (!empty($filters['dateTo'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['dateTo']));
        }
        if (!empty($filters['userType'])) {
            if ($filters['userType'] === 'registered') {
                $query->whereNotNull('user_id');
            } else {
                $query->whereNull('user_id');
            }
        }
        if (!empty($filters['minMessages'])) {
            $query->having('messages_count', '>=', $filters['minMessages']);
        }

        $sessions = $query->paginate(20);

        return [
            'sessions' => $sessions->items(),
            'meta'     => [
                'current_page' => $sessions->currentPage(),
                'last_page'    => $sessions->lastPage(),
                'total'        => $sessions->total(),
            ]
        ];
    }

    public function getSession(string $sessionId): array
    {
        $session = LyraSession::where('session_id', $sessionId)
            ->with(['messages' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }])
            ->firstOrFail();

        return $session->toArray();
    }

    public function persistMessage(array $data): \App\Models\LyraMessage
    {
        // MODIFIED: asociar user_id autenticado al crear/recuperar la sesión
        $session = LyraSession::updateOrCreate(
            ['session_id' => $data['session_id'] ?? 'default'],
            [
                'user_id'     => $data['user_id']     ?? null,
                'city'        => $data['city']        ?? null,
                'last_intent' => $data['intent']      ?? null,
                'metadata'    => array_merge(['ip' => request()->ip()], (array)($data['metadata'] ?? [])),
            ]
        );

        // MODIFIED: si la sesión existía como anónima y ahora el user está autenticado, actualizarla
        if (!$session->user_id && !empty($data['user_id'])) {
            $session->update(['user_id' => $data['user_id']]);
        }

        return LyraMessage::create([
            'lyra_session_id'    => $session->id,
            'role'               => $data['role']               ?? 'user',
            'content'            => $data['content']            ?? '',
            'intent'             => $data['intent']             ?? null,
            'confidence'         => $data['confidence']         ?? null,
            'entities'           => $data['entities']           ?? [],
            'thinking_process'   => $data['thinking_process']   ?? [],
            'processing_time_ms' => $data['processing_time_ms'] ?? 0,
            'created_at'         => now(),
        ]);
    }

    public function exportSessions(array $filters, string $format = 'csv'): mixed
    {
        $query = LyraSession::withCount('messages')->orderByDesc('created_at');
        // (Simplified export logic for brevity - returning raw data collection)
        return $query->limit(100)->get();
    }

    public function flagSession(string $id, string $flag): bool
    {
        $session = LyraSession::where('session_id', $id)->firstOrFail();
        $session->update(['status' => $flag]);
        return true;
    }
}