<?php

namespace App\Services;

use App\Models\LyraVersion;
use Illuminate\Support\Facades\Log;

class LyraVersionService
{
    public function getVersions(): array
    {
        // MODIFIED: Register current version if empty
        if (LyraVersion::count() === 0) {
            LyraVersion::create([
                'version'     => 'v2.1',
                'changelog'   => "Versión base instalada.\n• Motor NLP local activo.\n• Persistencia de historial.",
                'deployed_by' => 'System',
                'is_current'  => true,
                'metrics'     => ['success_rate' => 85, 'avg_response_ms' => 420],
                'deployed_at' => now()
            ]);
        }

        return LyraVersion::orderByDesc('deployed_at')->get()->toArray();
    }

    public function rollback(int $versionId, int $userId): bool
    {
        $version = LyraVersion::findOrFail($versionId);

        if ($version->is_current) {
            throw new \Exception("La versión {$version->version} ya es la versión activa.");
        }

        // MODIFIED: eliminar llamada duplicada a activar()
        $version->activar();

        Log::info('[LyraVersion] Rollback ejecutado', [
            'new_version' => $version->version,
            'user_id'     => $userId,
        ]);

        return true;
    }
}
