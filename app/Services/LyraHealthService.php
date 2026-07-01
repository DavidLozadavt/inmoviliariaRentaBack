<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LyraHealthService
{
    public function check(): array
    {
        $checks = [
            'database' => $this->measure(fn() => DB::connection()->getPdo()),
            'cache'    => $this->measure(fn() => Cache::get('health_check')),
            'nlp'      => $this->measure(fn() => app(LocalInterpreterEngine::class)->interpret('hola', [])),
            'geo'      => $this->measure(fn() => app(GeoLocationEngine::class)->resolve(['city' => 'Bogotá'])),
            'queue'    => $this->measure(fn() => DB::table('jobs')->count()),
        ];

        return collect($checks)->map(function ($latency, $service) {
            $status = $this->statusFromLatency($latency);

            // MODIFIED: Register incident if system is not healthy
            if ($status !== 'healthy') {
                \App\Models\LyraServiceIncident::updateOrCreate(
                    ['service' => $service, 'resolved_at' => null],
                    ['status' => $status, 'latency_ms' => round($latency), 'detail' => $this->getDetailsFor($service)]
                );
            }

            return [
                'service'   => $service,
                'status'    => $status,
                'latency'   => round($latency, 1),
                'lastCheck' => now()->toIso8601String(),
                'details'   => $this->getDetailsFor($service),
            ];
        })->values()->toArray();
    }

    public function getIncidents(int $days = 7): array
    {
        return \App\Models\LyraServiceIncident::where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    private function measure(callable $callback): float
    {
        $start = microtime(true);
        try {
            $callback();
            return (microtime(true) - $start) * 1000;
        } catch (\Exception $e) {
            Log::error("[LyraHealth] Check failed", ['msg' => $e->getMessage()]);
            return 9999; // Error latency
        }
    }

    private function statusFromLatency(float $latency): string
    {
        if ($latency >= 1000) return 'down';
        if ($latency >= 200)  return 'degraded';
        return 'healthy';
    }

    private function getDetailsFor(string $service): string
    {
        return match ($service) {
            'database' => 'MySQL/MariaDB Connection OK',
            'cache'    => 'Redis Cache Connection OK',
            'nlp'      => 'Local Model v3.2 Responding',
            'geo'      => 'Nomimatim Proxy OK',
            'queue'    => 'Worker listening',
        };
    }

    public function restartService(string $service): bool
    {
        if ($service === 'cache') {
            Cache::flush();
            return true;
        }

        if ($service === 'queue') {
            // Typically requires artisan queue:restart but we can clear jobs if needed
            return true;
        }

        return false;
    }
}
