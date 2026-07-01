<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    // ============================================================
    // CONFIGURACIÓN CENTRALIZADA
    // ============================================================

    /** Tamaño máximo del archivo crudo recibido del cliente (10 MB). */
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** Tamaño máximo almacenado tras compresión canvas (5 MB). */
    private const MAX_STORED_BYTES = 5 * 1024 * 1024;

    /** MIME types de imagen aceptados. */
    private const VALID_MIMES = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
        'image/webp', 'image/heic', 'image/heif', 'image/avif', 'image/bmp',
    ];

    /** Columnas ordenables (whitelist para evitar SQL injection en sort_by). */
    private const SORTABLE_COLUMNS = ['created_at', 'name', 'email', 'status', 'role'];

    // ============================================================
    // INDEX
    // ============================================================

    /**
     * Retorna usuarios paginados con filtros opcionales de búsqueda,
     * rol, estado y estado de verificación.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $sortBy  = in_array($request->input('sort_by'), self::SORTABLE_COLUMNS, true)
            ? $request->input('sort_by')
            : 'created_at';
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        $users = User::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(fn ($sub) =>
                    $sub->where('name',  'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                );
            })
            ->when($request->filled('role'),                fn ($q) => $q->where('role',                $request->role))
            ->when($request->filled('status'),              fn ($q) => $q->where('status',              $request->status))
            ->when($request->filled('verification_status'), fn ($q) => $q->where('verification_status', $request->verification_status))
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ],
        ]);
    }

    // ============================================================
    // SHOW
    // ============================================================

    public function show(int $id): JsonResponse
    {
        // findOrFail lanza ModelNotFoundException → 404 automático por Laravel
        return response()->json(User::findOrFail($id));
    }

    // ============================================================
    // STORE
    // ============================================================

    public function store(Request $request): JsonResponse
    {
        // validate() lanza ValidationException → 422 automático, sin try/catch manual
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'email'        => 'required|email|unique:users,email',
            'phone'        => 'required|string|max:20',
            'role'         => 'required|in:user,admin,support',
            'status'       => 'required|in:active,pending,inactive,suspended',
            'address'      => 'required|string',
            'id_documento' => 'required|string|unique:users,id_documento',
            'department'   => 'nullable|string|max:100',
            'city'         => 'nullable|string|max:100',
            'password'     => 'required|string|min:8',
            'photo'        => 'nullable|file|max:10240',
        ]);

        // Preparar datos del usuario
        $userData = collect($validated)
            ->except('photo', 'password')
            ->merge([
                'password'            => Hash::make($validated['password']),
                'verification_status' => 'pending',
            ])
            ->all();

        // Procesar foto si se incluyó
        if ($request->hasFile('photo')) {
            $photoResult = $this->processUploadedPhoto($request->file('photo'));

            if (! $photoResult['success']) {
                return response()->json(['success' => false, 'message' => $photoResult['message']], 422);
            }

            $userData['photo'] = $photoResult['data'];
        }

        try {
            $user = User::create($userData);
        } catch (Throwable $e) {
            Log::error('Error creando usuario', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al crear usuario'], 500);
        }

        if (Auth::check()) {
            ActivityService::logUserCreatedByAdmin($user, Auth::user());
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado correctamente',
            'user'    => $user,
        ], 201);
    }

    // ============================================================
    // UPDATE
    // ============================================================

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Construir reglas solo para los campos presentes en la petición
        $rules = array_filter([
            'name'         => $request->has('name')         ? 'string|max:255'                                           : null,
            'email'        => $request->has('email')         ? ['email', Rule::unique('users', 'email')->ignore($id)]    : null,
            'phone'        => $request->has('phone')         ? 'string|max:20'                                           : null,
            'role'         => $request->has('role')          ? 'in:user,admin,support'                                   : null,
            'status'       => $request->has('status')        ? 'in:active,pending,inactive,suspended'                    : null,
            'address'      => $request->has('address')       ? 'string'                                                  : null,
            'id_documento' => $request->has('id_documento')  ? ['string', Rule::unique('users', 'id_documento')->ignore($id)] : null,
            'department'   => $request->has('department')    ? 'nullable|string|max:100'                                 : null,
            'city'         => $request->has('city')          ? 'nullable|string|max:100'                                 : null,
            'bio'          => $request->has('bio')           ? 'nullable|string|max:500'                                 : null,
            'photo'        => $request->hasFile('photo')     ? 'file|max:10240'                                          : null,
        ]);

        $validated = $request->validate($rules);

        $previousRole = $user->role;

        // Actualizar campos de texto validados (excluir foto)
        $user->fill(collect($validated)->except('photo')->all());

        // Procesar foto si se incluyó
        if ($request->hasFile('photo')) {
            $photoResult = $this->processUploadedPhoto($request->file('photo'));

            if (! $photoResult['success']) {
                return response()->json(['success' => false, 'message' => $photoResult['message']], 422);
            }

            $user->photo = $photoResult['data'];

            Log::info("Foto actualizada para usuario {$id}", [
                'size_kb' => round(strlen($photoResult['data']) / 1024, 1),
            ]);
        }

        try {
            $user->save();
        } catch (Throwable $e) {
            Log::error('Error actualizando usuario', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al actualizar usuario'], 500);
        }

        // Registrar cambio de rol si ocurrió
        if ($request->has('role') && $previousRole !== $user->role && Auth::check()) {
            ActivityService::logUserRoleChanged($user, $previousRole, Auth::user());
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente',
            'user'    => $user->refresh(),
        ]);
    }

    // ============================================================
    // UPDATE STATUS
    // ============================================================

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $request->validate([
            'status' => 'required|in:active,pending,inactive,suspended',
        ]);

        $previousStatus = $user->status;
        $user->status   = $request->status;

        try {
            $user->save();
        } catch (Throwable $e) {
            Log::error('Error actualizando estado de usuario', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al actualizar estado'], 500);
        }

        if (Auth::check()) {
            ActivityService::logUserStatusChanged($user, $previousStatus, Auth::user());
        }

        return response()->json([
            'success' => true,
            'message' => 'Estado del usuario actualizado',
            'user'    => $user,
        ]);
    }

    // ============================================================
    // DESTROY
    // ============================================================

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if (Auth::check()) {
            ActivityService::logUserDeleted($user, Auth::user());
        }

        try {
            $user->delete();
        } catch (Throwable $e) {
            Log::error('Error eliminando usuario', ['id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al eliminar usuario'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario eliminado correctamente',
        ]);
    }

    // ============================================================
    // STATS
    // ============================================================

    /**
     * Devuelve totales por estado y rol usando una sola consulta agregada
     * en lugar de múltiples count() separados → mucho más eficiente.
     */
    public function getStats(): JsonResponse
    {
        // Una sola pasada sobre la tabla en lugar de 8 queries
        $byStatus = User::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byRole = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return response()->json([
            'total'     => $byStatus->sum(),
            'active'    => $byStatus->get('active',    0),
            'inactive'  => $byStatus->get('inactive',  0),
            'pending'   => $byStatus->get('pending',   0),
            'suspended' => $byStatus->get('suspended', 0),
            'byRole'    => [
                'user'    => $byRole->get('user',    0),
                'admin'   => $byRole->get('admin',   0),
                'support' => $byRole->get('support', 0),
            ],
        ]);
    }

    // ============================================================
    // HELPER PRIVADO: procesar foto subida
    // ============================================================

    /**
     * Valida y convierte una imagen subida a base64 data-URI.
     *
     * El frontend (canvas) ya debería enviar JPEG comprimido,
     * pero se aplica una segunda capa de validación por seguridad.
     *
     * @return array{success: bool, message: string, data: string|null}
     */
    private function processUploadedPhoto(UploadedFile $image): array
    {
        $fail = fn (string $msg) => ['success' => false, 'message' => $msg, 'data' => null];

        // 1. Integridad del archivo
        if (! $image->isValid()) {
            Log::warning('Foto inválida recibida', ['error' => $image->getErrorMessage()]);
            return $fail('El archivo de imagen no es válido o está corrupto.');
        }

        // 2. Tamaño máximo crudo
        if ($image->getSize() > self::MAX_UPLOAD_BYTES) {
            return $fail('La imagen supera el límite de 10 MB.');
        }

        // 3. MIME type (finfo es más fiable que el declarado por el cliente)
        $mimeType = $image->getMimeType();

        // Fallback para HEIC y tipos que finfo no detecta
        if (empty($mimeType) || $mimeType === 'application/octet-stream') {
            $mimeType = $image->getClientMimeType();
        }

        if (! in_array($mimeType, self::VALID_MIMES, true)) {
            Log::warning("MIME no permitido '{$mimeType}' rechazado.");
            return $fail("Formato de imagen no soportado ({$mimeType}). Usa JPG, PNG, GIF, WebP o HEIC.");
        }

        // 4. Leer contenido
        $imageData = @file_get_contents($image->getRealPath());

        if ($imageData === false || $imageData === '') {
            Log::error('No se pudo leer el archivo de imagen del servidor.');
            return $fail('No se pudo leer el archivo de imagen en el servidor.');
        }

        // 5. Tamaño tras lectura (doble comprobación)
        if (strlen($imageData) > self::MAX_STORED_BYTES) {
            return $fail('La imagen procesada sigue siendo demasiado grande (máx. 5 MB). Usa una imagen más pequeña.');
        }

        // 6. Generar data-URI base64
        return [
            'success' => true,
            'message' => 'Imagen procesada correctamente.',
            'data'    => "data:{$mimeType};base64," . base64_encode($imageData),
        ];
    }
}