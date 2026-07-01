<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LyraConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AdminLyraConfigController extends Controller
{
    private LyraConfigService $configService;

    public function __construct(LyraConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function show(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->configService->getAll(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraConfig] Error in show', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar configuración: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $this->configService->update($data, auth()->id() ?: 0);

            return response()->json([
                'success' => true,
                'message' => 'Configuration updated successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraConfig] Error in update', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar configuración: ' . $e->getMessage()
            ], 500);
        }
    }
}
