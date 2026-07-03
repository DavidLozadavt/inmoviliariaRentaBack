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
        $expectedToken = env('BRIDGE_SECRET_TOKEN', 'VirtualT_Bridge_Secret_2026');

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

        if (User::where('id_documento', $request->id_documento)->exists()) {
            return response()->json(['error' => 'Ya existe una inmobiliaria registrada con este documento.'], 422);
        }

        if (User::where('email', $request->email)->exists()) {
            return response()->json(['error' => 'El correo electrónico ya se encuentra registrado en el sistema.'], 422);
        }

        try {
            DB::beginTransaction();

            $tempPassword = 'VirtualT2026!';
            $password = $request->filled('contrasena_hash')
                ? $request->contrasena_hash // Ya viene encriptada del ERP
                : Hash::make($tempPassword);

            $phone = $request->telefono ?: ('sin-telefono-' . uniqid());

            $userId = DB::table('users')->insertGetId([
                'name' => $request->nombre,
                'email' => $request->email,
                'password' => $password,
                'phone' => $phone,
                'address' => $request->direccion ?? 'No especificada',
                'id_documento' => $request->id_documento,
                'status' => 'active',
                'verification_status' => 'verified',
                'email_verified_at' => now(),
                'role' => 'landlord',
                'city' => $request->ciudad,
                'department' => $request->departamento,
                'bio' => $request->bio,
                'photo' => $request->photo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            Log::info('Inmobiliaria sincronizada desde ERP', ['user_id' => $userId, 'email' => $request->email]);

            return response()->json([
                'message' => 'Inmobiliaria registrada con éxito.',
                'user_id' => $userId,
                'temp_password' => $request->filled('contrasena_hash') ? null : $tempPassword,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error registrando inmobiliaria desde el puente API:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Ocurrió un error en el servidor de RentUs: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Recibe la sincronización de contraseña cuando el usuario la cambia en
     * nexiservice o School. No dispara nada de vuelta (evita ping-pong).
     */
    public function syncPassword(Request $request): JsonResponse
    {
        $receivedToken = $request->header('X-Bridge-Token');
        $expectedToken = env('BRIDGE_SECRET_TOKEN', 'VirtualT_Bridge_Secret_2026');

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
