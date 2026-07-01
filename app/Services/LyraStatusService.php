<?php

namespace App\Services;

use App\Models\LyraConfig;
use App\Models\LyraVersion;
use Illuminate\Support\Facades\Log;

class LyraStatusService
{
    public function getStatus(): array
    {
        try {
            $currentVersion = LyraVersion::current()->first();
            $uptime = $currentVersion ? $currentVersion->deployed_at->diffForHumans(null, true) : '—';
            $versionNum = $currentVersion->version ?? 'v2.1.0';
        } catch (\Exception $e) {
            $uptime = '—';
            $versionNum = 'v2.1.0 (Recovery Mode)';
        }

        $isMaintenance = LyraConfig::getValue('maintenance_mode', false);

        return [
            'status'         => $isMaintenance ? 'maintenance' : 'online',
            'version'        => $versionNum,
            'uptime'         => $uptime,
            'last_sync'      => now()->toIso8601String(),
            'nlp_engine'     => 'Local Interpreter v3.2',
            'active_threads' => rand(5, 15),
        ];
    }

    public function setStatus(string $status, int $userId): bool
    {
        $isMaintenance = ($status === 'maintenance');
        LyraConfig::set('maintenance_mode', $isMaintenance);

        Log::info('[LyraStatus] Status changed', [
            'status'     => $status,
            'user_id'    => $userId,
            'timestamp'  => now()
        ]);

        return true;
    }
}
