<?php

namespace App\Services;

use App\Models\LyraSession;
use App\Models\LyraMessage;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LyraStatsService
{
    public function getStats(string $period = '7d'): array
    {
        $days     = $this->resolvePeriod($period);
        $fromDate = now()->subDays($days);

        $totalChats  = LyraSession::where('created_at', '>=', $fromDate)->count();
        $todayChats  = LyraSession::whereDate('created_at', today())->count();

        // Avg messages per session
        $avgMessages = LyraSession::where('created_at', '>=', $fromDate)
            ->withCount('messages')
            ->get()
            ->avg('messages_count') ?? 0;

        // Success Rate (intent !== fuera_de_contexto)
        $totalMessagesCount = LyraMessage::where('role', 'user')
            ->where('created_at', '>=', $fromDate)
            ->count();
        $successfulMessages = LyraMessage::where('role', 'user')
            ->where('created_at', '>=', $fromDate)
            ->where('intent', '!=', 'fuera_de_contexto')
            ->whereNotNull('intent')
            ->count();
        $successRate = $totalMessagesCount > 0 ? ($successfulMessages / $totalMessagesCount) * 100 : 85;

        // Weekly Trend
        $weeklyTrend = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $weeklyTrend[] = [
                'date' => $date,
                'chats' => LyraSession::whereDate('created_at', $date)->count()
            ];
        }

        // Top City & Intent
        $topCity   = LyraSession::where('created_at', '>=', $fromDate)
            ->select('city', DB::raw('count(*) as total'))
            ->whereNotNull('city')
            ->groupBy('city')
            ->orderByDesc('total')
            ->first()?->city ?? 'Bogotá';

        $topIntent = LyraMessage::where('role', 'user')
            ->where('created_at', '>=', $fromDate)
            ->select('intent', DB::raw('count(*) as total'))
            ->groupBy('intent')
            ->orderByDesc('total')
            ->first()?->intent ?? 'buscar_propiedad';

        return [
            'totalChats'  => $totalChats,
            'todayChats'  => $todayChats,
            'avgMessages' => round($avgMessages, 1),
            'successRate' => round($successRate, 1),
            'weeklyTrend' => $weeklyTrend,
            'topCity'     => $topCity,
            'topIntent'   => $topIntent,
        ];
    }

    public function getIntentStats(string $period = '7d'): array
    {
        $days     = $this->resolvePeriod($period);
        $fromDate = now()->subDays($days);

        $total = LyraMessage::where('role', 'user')
            ->where('created_at', '>=', $fromDate)
            ->count() ?: 1;

        $stats = LyraMessage::where('role', 'user')
            ->where('created_at', '>=', $fromDate)
            ->select('intent', DB::raw('count(*) as count'))
            ->groupBy('intent')
            ->orderByDesc('count')
            ->get()
            ->map(function ($item) use ($total) {
                return [
                    'intent'     => $item->intent ?? 'desconocido',
                    'count'      => $item->count,
                    'percentage' => round(($item->count / $total) * 100, 1)
                ];
            });

        return $stats->toArray();
    }

    public function getHourlyStats(): array
    {
        return DB::table('lyra_messages')
            ->selectRaw('DAYOFWEEK(created_at) as day, HOUR(created_at) as hour, COUNT(*) as count')
            ->whereBetween('created_at', [now()->subDays(7), now()])
            ->groupByRaw('DAYOFWEEK(created_at), HOUR(created_at)')
            ->get()
            ->toArray();
    }

    private function resolvePeriod(string $period): int
    {
        return match ($period) {
            'today' => 1,
            '30d'   => 30,
            default => 7,
        };
    }
}
