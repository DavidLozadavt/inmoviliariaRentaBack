<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LyraStatusService;
use App\Services\LyraStatsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\LyraSession;
use App\Models\LyraMessage;

class AdminLyraController extends Controller
{
    private LyraStatusService $statusService;
    private LyraStatsService  $statsService;

    public function __construct(LyraStatusService $statusService, LyraStatsService $statsService)
    {
        $this->statusService = $statusService;
        $this->statsService  = $statsService;
    }

    public function status(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->statusService->getStatus()
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Error fetching status', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el estado: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'status' => 'required|in:online,maintenance'
            ]);

            $isMaintenance = $request->status === 'maintenance';

            \App\Models\LyraConfig::setValue(
                'maintenance_mode',
                $isMaintenance,
                auth()->user()?->name ?? 'admin'
            );

            $maintenanceMessage = \App\Models\LyraConfig::getValue(
                'maintenance_message',
                'Lyra está en mantenimiento. Vuelve pronto.'
            );

            broadcast(new \App\Events\LyraStatusChanged(
                $request->status,
                $isMaintenance ? $maintenanceMessage : ''
            ));

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => $request->status,
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Error updating status', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el estado: ' . $e->getMessage()
            ], 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '7d');
            return response()->json([
                'success' => true,
                'data'    => $this->statsService->getStats($period),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Error fetching stats', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function intentStats(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '7d');
            return response()->json([
                'success' => true,
                'data'    => $this->statsService->getIntentStats($period),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Error fetching intent stats', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar estadísticas de intención: ' . $e->getMessage()
            ], 500);
        }
    }

    public function hourlyStats(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->statsService->getHourlyStats(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Error fetching hourly stats', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar estadísticas por hora: ' . $e->getMessage()
            ], 500);
        }
    }

    public function clearCache(): JsonResponse
    {
        try {
            Cache::flush();
            return response()->json([
                'success' => true,
                'message' => 'Cache cleared successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Error clearing cache', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al limpiar caché: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /admin/lyra/sessions
     * MODIFIED: incluir datos del usuario para no mostrar "anónimo" cuando hay user_id
     */
    public function sessions(Request $request): JsonResponse
    {
        try {
            $sessions = \App\Models\LyraSession::with(['user:id,name,email,role'])
                ->when($request->intent,    fn($q, $v) => $q->where('last_intent', $v))
                ->when($request->city,      fn($q, $v) => $q->where('city', $v))
                ->when($request->status,    fn($q, $v) => $v !== 'all' ? $q->where('status', $v) : $q)
                ->when($request->search,    fn($q, $v) => $q->where(function ($q) use ($v) {
                    $q->where('session_id', 'like', "%$v%")
                        ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%$v%")
                            ->orWhere('email', 'like', "%$v%"));
                }))
                ->when($request->dateFrom,  fn($q, $v) => $q->whereDate('created_at', '>=', $v))
                ->when($request->dateTo,    fn($q, $v) => $q->whereDate('created_at', '<=', $v))
                ->when($request->userType === 'registered', fn($q) => $q->whereNotNull('user_id'))
                ->when($request->userType === 'anonymous',  fn($q) => $q->whereNull('user_id'))
                ->withCount('messages')
                ->orderBy($request->sortBy ?? 'updated_at', $request->sortDir ?? 'desc')
                ->paginate($request->perPage ?? 20);

            return response()->json([
                'success' => true,
                'data'    => $sessions->map(fn($s) => [
                    'id'             => $s->session_id,
                    'status'         => $s->status ?? 'active',
                    'city'           => $s->city,
                    'last_intent'    => $s->last_intent,
                    'messages_count' => $s->messages_count,
                    'is_anonymous'   => is_null($s->user_id),
                    'created_at'     => $s->created_at->toISOString(),
                    'updated_at'     => $s->updated_at->toISOString(),
                    'user'           => $s->user ? [
                        'id'       => $s->user->id,
                        'name'     => $s->user->name,
                        'email'    => $s->user->email,
                        'role'     => $s->user->role,
                        'initials' => strtoupper(substr($s->user->name, 0, 1) . (strpos($s->user->name, ' ') !== false ? substr(strrchr($s->user->name, ' '), 1, 1) : '')),
                    ] : null,
                ]),
                'meta' => [
                    'total'        => $sessions->total(),
                    'current_page' => $sessions->currentPage(),
                    'last_page'    => $sessions->lastPage(),
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyra] Fatal error in sessions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar las sesiones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /admin/lyra/sessions/stats
     * NUEVO: estadísticas en tiempo real para el dashboard de sesiones
     */
    public function sessionStats(): JsonResponse
    {
        $today = now()->startOfDay();

        return response()->json([
            'success' => true,
            'data' => [
                'total_sessions'     => \App\Models\LyraSession::count(),
                'active_today'       => \App\Models\LyraSession::where('updated_at', '>=', $today)->count(),
                'anonymous_today'    => \App\Models\LyraSession::whereNull('user_id')->where('created_at', '>=', $today)->count(),
                'registered_today'   => \App\Models\LyraSession::whereNotNull('user_id')->where('created_at', '>=', $today)->count(),
                'flagged'            => \App\Models\LyraSession::where('status', 'flagged')->count(),
                'avg_messages'       => round(\App\Models\LyraMessage::count() / max(\App\Models\LyraSession::count(), 1), 1),
                'top_intents'        => \App\Models\LyraSession::selectRaw('last_intent, count(*) as total')
                    ->whereNotNull('last_intent')
                    ->groupBy('last_intent')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'last_intent'),
                'top_cities'         => \App\Models\LyraSession::selectRaw('city, count(*) as total')
                    ->whereNotNull('city')
                    ->groupBy('city')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'city'),
            ],
        ]);
    }

    /**
     * GET /admin/lyra/sessions/{id}
     * MODIFIED: eager load completo para el detalle
     */
    public function sessionDetail(string $id): JsonResponse
    {
        try {
            $session = \App\Models\LyraSession::with([
                'user:id,name,email,role,created_at',
                'messages' => fn($q) => $q->orderBy('created_at')
            ])->where('session_id', $id)->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'             => $session->session_id,
                    'status'         => $session->status,
                    'city'           => $session->city,
                    'last_intent'    => $session->last_intent,
                    'is_anonymous'   => is_null($session->user_id),
                    'created_at'     => $session->created_at->toISOString(),
                    'metadata'       => $session->metadata,
                    'user'           => $session->user ? [
                        'id'       => $session->user->id,
                        'name'     => $session->user->name,
                        'email'    => $session->user->email,
                        'role'     => $session->user->role,
                        'initials' => strtoupper(substr($session->user->name, 0, 1) . (strpos($session->user->name, ' ') !== false ? substr(strrchr($session->user->name, ' '), 1, 1) : '')),
                    ] : null,
                    'messages' => $session->messages->map(fn($m) => [
                        'id'               => $m->id,
                        'role'             => $m->role,
                        'content'          => $m->content,
                        'intent'           => $m->intent,
                        'confidence'       => $m->confidence,
                        'entities'         => $m->entities,
                        'thinking_process' => $m->thinking_process,
                        'processing_ms'    => $m->processing_time_ms ?? 0,
                        'created_at'       => $m->created_at ? $m->created_at->toISOString() : now()->toISOString(),
                    ]),
                ]
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[AdminLyra] Fatal error in sessionDetail', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el detalle de la sesión: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * PATCH /admin/lyra/sessions/{id}
     */
    public function updateSession(Request $request, string $id): JsonResponse
    {
        $session = \App\Models\LyraSession::where('session_id', $id)->firstOrFail();
        $session->update($request->only(['status', 'city', 'last_intent']));

        broadcast(new \App\Events\LyraSessionUpdated(
            $session->session_id,
            $session->status,
            $session->last_intent,
            $session->city
        ));

        return response()->json(['success' => true, 'data' => $session]);
    }

    /**
     * DELETE /admin/lyra/sessions/{id}
     * NUEVO
     */
    public function deleteSession(string $id): JsonResponse
    {
        $session = \App\Models\LyraSession::where('session_id', $id)->firstOrFail();
        $session->delete();

        // Broadcast para actualizar otros admins en tiempo real
        broadcast(new \App\Events\LyraSessionDeleted($id));

        return response()->json(['success' => true]);
    }

    /**
     * POST /admin/lyra/sessions/{id}/block
     * NUEVO
     */
    public function blockSession(Request $request, string $id): JsonResponse
    {
        $session = \App\Models\LyraSession::with('user')->where('session_id', $id)->firstOrFail();

        $session->update([
            'status'   => 'blocked',
            'metadata' => array_merge($session->metadata ?? [], [
                'blocked_at'  => now()->toISOString(),
                'blocked_by'  => auth()->user()->name,
                'block_reason' => $request->reason ?? 'Bloqueado desde el dashboard',
            ])
        ]);

        broadcast(new \App\Events\LyraSessionUpdated($id, 'blocked'));

        return response()->json(['success' => true, 'data' => ['status' => 'blocked']]);
    }

    /**
     * GET /admin/lyra/sessions/{id}/replay
     * NUEVO
     */
    public function replaySession(string $id): JsonResponse
    {
        $session  = \App\Models\LyraSession::with(['messages' => fn($q) => $q->orderBy('created_at'), 'user:id,name,email'])->where('session_id', $id)->firstOrFail();
        $messages = $session->messages;
        $start    = $messages->first()?->created_at;

        $timeline = $messages->map(fn($msg, $i) => [
            'id'              => $msg->id,
            'role'            => $msg->role,
            'content'         => $msg->content,
            'intent'          => $msg->intent,
            'confidence'      => $msg->confidence,
            'entities'        => $msg->entities,
            'thinking_process' => $msg->thinking_process,
            'processing_ms'   => $msg->processing_time_ms,
            'elapsed_seconds' => $start ? $msg->created_at->diffInSeconds($start) : 0,
            'created_at'      => $msg->created_at->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'session'    => ['id' => $session->session_id, 'user' => $session->user],
                'timeline'   => $timeline,
                'duration_s' => $messages->count() > 1
                    ? $messages->last()->created_at->diffInSeconds($start)
                    : 0,
            ],
        ]);
    }

    public function exportSessions(Request $request): JsonResponse
    {
        $filters = $request->all();
        $format  = $request->query('format', 'csv');
        $data    = \App\Models\LyraSession::with(['user', 'messages'])->limit(500)->get();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
