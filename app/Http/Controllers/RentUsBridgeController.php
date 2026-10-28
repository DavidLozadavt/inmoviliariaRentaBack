<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RentUsBridgeController extends Controller
{
    /**
     * Endpoint de integración: registra el login de una inmobiliaria aprobada
     * en el ERP (que a su vez viene de un registro en nexiservice).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function inscribirInmobiliaria(Request $request): JsonResponse
    {
        $receivedToken = $request->header('X-Bridge-Token');
        $expectedToken = config('services.bridge.token');

        if (empty($receivedToken) || $receivedToken !== $expectedToken) {
            return response()->json(['error' => 'No autorizado. Token de seguridad inválido o ausente.'], 401);
        }

        $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'id_documento' => 'required|string|max:45',
            'telefono' => 'nullable|string|max:45',
            'direccion' => 'nullable|string|max:255',
            'ciudad' => 'nullable|string|max:255',
            'departamento' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'contrasena_hash' => 'nullable|string',
            'photo' => 'nullable|string',
        ]);

        $datos = [
            'name' => $request->nombre,
            'phone' => $request->telefono ?: null,
            'address' => $request->direccion ?? 'No especificada',
            'status' => 'active',
            'verification_status' => 'verified',
            'city' => $request->ciudad,
            'department' => $request->departamento,
            'bio' => $request->bio,
            'photo' => $request->photo,
            'updated_at' => now(),
        ];

        try {
            DB::beginTransaction();

            // Igual que inscribe-empresa del ERP business: se busca por correo y,
            // si ya existe, se actualiza (así se puede reintentar sin error).
            $existente = User::where('email', $request->email)->first();

            // El documento ya lo usa otra cuenta (otro correo): no se mezclan.
            $documentoOcupado = User::where('id_documento', $request->id_documento)
                ->when($existente, fn ($q) => $q->where('id', '!=', $existente->id))
                ->exists();
            if ($documentoOcupado) {
                DB::rollBack();
                return response()->json(['error' => 'Ya existe otra cuenta registrada con este documento.'], 422);
            }

            $tempPassword = 'VirtualT2026!';
            $conClaveNexi = $request->filled('contrasena_hash');

            if ($existente) {
                $cambios = array_filter($datos, fn ($v) => $v !== null) + [
                    'id_documento' => $request->id_documento,
                    'email_verified_at' => $existente->email_verified_at ?? now(),
                ];
                // Un administrador de NexiLiving no se convierte en arrendador.
                if ($existente->role !== 'admin') {
                    $cambios['role'] = 'landlord';
                }
                if ($conClaveNexi) {
                    $cambios['password'] = $request->contrasena_hash;
                }
                DB::table('users')->where('id', $existente->id)->update($cambios);
                $userId = $existente->id;
            } else {
                $userId = DB::table('users')->insertGetId([
                    'email' => $request->email,
                    'password' => $conClaveNexi ? $request->contrasena_hash : Hash::make($tempPassword),
                    'phone' => $request->telefono ?: ('sin-telefono-' . uniqid()),
                    'id_documento' => $request->id_documento,
                    'email_verified_at' => now(),
                    'role' => 'landlord',
                    'created_at' => now(),
                ] + $datos);
            }

            DB::commit();

            Log::info('Inmobiliaria sincronizada desde ERP', ['user_id' => $userId, 'email' => $request->email]);

            return response()->json([
                'message' => 'Inmobiliaria registrada con éxito.',
                'user_id' => $userId,
                'temp_password' => (!$existente && !$conClaveNexi) ? $tempPassword : null,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error registrando inmobiliaria desde el puente API:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Ocurrió un error en el servidor de NexiLiving: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Recibe la sincronización de contraseña cuando el usuario la cambia en
     * nexiservice o School. No dispara nada de vuelta (evita ping-pong).
     */
    public function syncPassword(Request $request): JsonResponse
    {
        $receivedToken = $request->header('X-Bridge-Token');
        $expectedToken = config('services.bridge.token');

        if (empty($receivedToken) || $receivedToken !== $expectedToken) {
            return response()->json(['error' => 'No autorizado.'], 401);
        }

        $request->validate([
            'email' => 'required|email',
            'contrasena_hash' => 'required|string',
        ]);

        $updated = DB::table('users')
            ->where('email', strtolower(trim($request->email)))
            ->update(['password' => $request->contrasena_hash]);

        return response()->json(['matched' => (bool) $updated]);
    }
}
