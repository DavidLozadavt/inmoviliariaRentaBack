<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LyraHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class AdminLyraHealthController extends Controller
{
    private LyraHealthService $healthService;

    public function __construct(LyraHealthService $healthService)
    {
        $this->healthService = $healthService;
    }

    public function check(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->healthService->check(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraHealth] Fatal error in check', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error de salud del sistema: ' . $e->getMessage()
            ], 500);
        }
    }

    public function restart($service): JsonResponse
    {
        try {
            $success = $this->healthService->restartService($service);

            return response()->json([
                'success' => $success,
                'message' => $success ? "Service $service restarted successfully" : "Failed to restart $service",
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraHealth] Error restarting service', [
                'service' => $service,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al reiniciar servicio: ' . $e->getMessage()
            ], 500);
        }
    }

    public function incidents(Request $request): JsonResponse
    {
        try {
            $days = $request->query('days', 7);
            return response()->json([
                'success' => true,
                'data'    => $this->healthService->getIncidents($days),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraHealth] Error fetching incidents', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar incidentes: ' . $e->getMessage()
            ], 500);
        }
    }
}
