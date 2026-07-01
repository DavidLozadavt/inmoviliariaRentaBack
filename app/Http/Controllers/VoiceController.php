<?php

namespace App\Http\Controllers;

use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VoiceController extends Controller
{
    private VoiceService $voiceService;

    public function __construct(VoiceService $voiceService)
    {
        $this->voiceService = $voiceService;
    }

    /**
     * POST /api/voice/interpret
     *
     * Receives transcribed speech text, interprets intent via Gemini,
     * and returns matching properties + map coordinates.
     */
    public function interpret(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'text'       => 'required|string|min:1|max:500',
            'session_id' => 'required|string|max:100',
            'history'    => 'sometimes|array|max:6',
            'history.*.role'    => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $text    = trim($request->input('text'));
            $history = $request->input('history', []);

            Log::info('[VoiceController] interpret', [
                'session_id' => $request->input('session_id'),
                'text'       => $text,
            ]);

            $result = $this->voiceService->interpret($text, $history);

            return response()->json($result, 200);

        } catch (\Exception $e) {
            Log::error('[VoiceController] Exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success'       => false,
                'intent'        => ['intent' => 'error'],
                'properties'    => [],
                'map_center'    => null,
                'response_text' => 'Lo siento, hubo un error procesando tu solicitud. Inténtalo de nuevo.',
            ], 500);
        }
    }
}
