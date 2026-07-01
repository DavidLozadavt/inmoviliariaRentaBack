<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LyraVersionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AdminLyraVersionController extends Controller
{
    private LyraVersionService $versionService;

    public function __construct(LyraVersionService $versionService)
    {
        $this->versionService = $versionService;
    }

    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->versionService->getVersions(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraVersion] Error in index', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar versiones: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**
     * POST /admin/lyra/versions
     * Registra una nueva versión de Lyra sin activarla automáticamente.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'version'    => 'required|string|unique:lyra_versions,version|regex:/^v\d+\.\d+(\.\d+)?$/',
            'changelog'  => 'required|string|max:2000',
            'activate'   => 'boolean',
            'metrics'    => 'nullable|array',
            'metrics.success_rate'    => 'nullable|numeric|min:0|max:100',
            'metrics.avg_response_ms' => 'nullable|numeric|min:0',
        ]);

        try {
            $version = \App\Models\LyraVersion::create([
                'version'     => $request->version,
                'changelog'   => $request->changelog,
                'deployed_by' => auth()->user()?->name ?? 'Admin',
                'is_current'  => false,
                'deployed_at' => now(),
                'metrics'     => $request->metrics ?? [],
            ]);

            // Si el admin quiere activarla inmediatamente
            if ($request->boolean('activate')) {
                $version->activar();
            }

            Log::info('[LyraVersion] Nueva versión creada', [
                'version'    => $version->version,
                'activated'  => $request->boolean('activate'),
                'created_by' => auth()->user()?->name,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Versión {$version->version} registrada correctamente.",
                'data'    => $version->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[LyraVersion] Error al crear versión', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al crear versión: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /admin/lyra/versions/{id}/rollback
     * Activa una versión anterior como la versión actual.
     */
    public function rollback($id, Request $request): JsonResponse
    {
        try {
            $this->versionService->rollback($id, auth()->id() ?: 0);

            return response()->json([
                'success' => true,
                'message' => 'Rollback ejecutado correctamente.',
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraVersion] Error en rollback', [
                'version_id' => $id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
