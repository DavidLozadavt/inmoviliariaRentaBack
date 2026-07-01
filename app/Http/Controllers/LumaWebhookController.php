<?php

namespace App\Http\Controllers;

use App\Services\ThreeDModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LumaWebhookController extends Controller
{
    public function __construct(private ThreeDModelService $service) {}

    /**
     * Recibe notificaciones de Luma AI cuando un modelo 3D está listo o falla.
     * POST /api/webhooks/luma
     *
     * ──────────────────────────────────────────────────────────────────────
     * NOTA SOBRE SEGURIDAD DEL WEBHOOK:
     *
     * A la fecha (Marzo 2026), Luma AI NO envía firma HMAC ni header de
     * autenticación en sus webhooks. Simplemente hacen POST al callback_url
     * con el objeto de generación como body. Reintentan hasta 3 veces si
     * no reciben HTTP 200, con delay de 100ms y timeout de 5s por intento.
     *
     * La autenticación implícita se hace validando que el luma_task_id
     * existe en nuestra base de datos (solo nosotros creamos esos registros
     * al enviar un video a Luma).
     *
     * Si Luma implementa firma en el futuro:
     * 1. Establecer LUMA_WEBHOOK_SECRET en .env
     * 2. Leer el header de firma (ej: X-Luma-Signature)
     * 3. Recalcular HMAC-SHA256 del body con el secret
     * 4. Comparar con hash_equals()
     * ──────────────────────────────────────────────────────────────────────
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Luma webhook received', [
            'task_id' => $payload['uuid'] ?? $payload['id'] ?? 'unknown',
            'status'  => $payload['status'] ?? 'unknown',
        ]);

        try {
            $this->service->handleWebhook($payload);
            return response()->json(['received' => true]);
        } catch (\Throwable $e) {
            Log::error('Luma webhook processing error', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
            // Retornamos 200 para evitar reintentos innecesarios de Luma
            // si el error es de nuestra lógica interna
            return response()->json(['received' => true, 'error' => $e->getMessage()]);
        }
    }
}
