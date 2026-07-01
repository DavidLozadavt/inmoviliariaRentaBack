<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LyraAdminAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AdminLyraAlertController extends Controller
{
    public function pending(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => LyraAdminAlert::unread()->orderByDesc('created_at')->get(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraAlert] Fatal error in pending', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar las alertas: ' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    public function markAsRead(int $id): JsonResponse
    {
        try {
            $alert = LyraAdminAlert::findOrFail($id);
            $alert->update(['read_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Alerta marcada como leída',
            ]);
        } catch (\Throwable $e) {
            Log::error('[AdminLyraAlert] Fatal error in markAsRead', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al marcar alerta: ' . $e->getMessage()
            ], 500);
        }
    }
}
