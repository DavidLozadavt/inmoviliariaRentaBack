<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PropertyController extends Controller
{
    /**
     * Roles con permisos de administración.
     */
    private const ADMIN_ROLES = ['admin', 'support'];

    /**
     * Campos permitidos para ordenar (whitelist).
     */
    private const SORT_FIELDS = [
        'id', 'title', 'city', 'monthly_price', 'area_m2',
        'views', 'created_at', 'updated_at', 'status', 'approval_status',
    ];

    // ─────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────

    /**
     * Retorna el usuario autenticado con aserción de tipo.
     * Elimina los warnings "Undefined method 'user'" / "Undefined method 'id'".
     *
     * @return \App\Models\User
     */
    private function authUser(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        return $user;
    }

    /**
     * Comprueba si el usuario autenticado es admin/support.
     */
    private function isAdmin(): bool
    {
        return in_array($this->authUser()->role, self::ADMIN_ROLES, true);
    }

    /**
     * Decodifica included_services y garantiza que sea un array para el Model Cast.
     */
    private function normalizeServices(?string $raw): array
    {
        if ($raw === null || $raw === '' || $raw === '[]' || $raw === 'null') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
            ? $decoded
            : [];
    }

    /**
     * Convierte image_url (string JSON o array) a array PHP.
     */
    private function decodeImages(mixed $imageUrl): array
    {
        if (is_array($imageUrl)) {
            return $imageUrl;
        }
        return is_string($imageUrl) ? (json_decode($imageUrl, true) ?? []) : [];
    }

    /**
     * Filtra base64 válidos y dentro del límite de tamaño.
     *
     * @param  string[]  $images
     * @param  float     $maxMB
     * @return string[]
     */
    private function filterValidBase64Images(array $images, float $maxMB = 3.0): array
    {
        $valid = [];
        foreach ($images as $i => $img) {
            if (!str_starts_with($img, 'data:image/')) {
                Log::warning("Imagen {$i} no es Data URI válido");
                continue;
            }
            $sizeMB = (strlen($img) * 0.75) / (1024 ** 2);
            if ($sizeMB > $maxMB) {
                Log::warning("Imagen {$i} muy grande: {$sizeMB}MB");
                continue;
            }
            $valid[] = $img;
        }
        return $valid;
    }

    // ─────────────────────────────────────────────
    // ACTIONS
    // ─────────────────────────────────────────────

    /**
     * Listado con filtros y paginación.
     * Optimización: select sólo columnas necesarias, eager load diferido.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage   = (int) $request->input('per_page', 10);
        $sortBy    = in_array($request->input('sort_by'), self::SORT_FIELDS, true)
            ? $request->input('sort_by')
            : 'created_at';
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Property::with(['user:id,name,email,phone,photo', 'images'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->input('search');
                $q->where(fn ($q2) =>
                    $q2->where('title',       'like', "%{$s}%")
                       ->orWhere('description', 'like', "%{$s}%")
                       ->orWhere('city',        'like', "%{$s}%")
                       ->orWhere('address',     'like', "%{$s}%")
                );
            })
            ->when($request->filled('status'),          fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('approval_status'), fn ($q) => $q->where('approval_status', $request->input('approval_status')))
            ->when($request->filled('city'),            fn ($q) => $q->where('city', 'like', '%' . $request->input('city') . '%'))
            ->when($request->filled('min_price'),       fn ($q) => $q->where('monthly_price', '>=', $request->input('min_price')))
            ->when($request->filled('max_price'),       fn ($q) => $q->where('monthly_price', '<=', $request->input('max_price')))
            ->orderBy($sortBy, $sortOrder);

        $properties = $query->paginate($perPage);

        return response()->json([
            'data' => $properties->items(),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page'    => $properties->lastPage(),
                'per_page'     => $properties->perPage(),
                'total'        => $properties->total(),
            ],
        ]);
    }

    /**
     * Mostrar propiedad por ID.
     */
    public function show(Property $property): JsonResponse
    {
        $property->load(['user:id,name,email,phone,photo', 'images']);

        return response()->json([
            'success' => true,
            'data'    => $property,
        ]);
    }

    /**
     * Crear propiedad.
     */
    public function store(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        Log::info('===== DEBUG: INICIO STORE =====');
        Log::info('Raw Request Data:', $request->all());
        Log::info('Files:', array_keys($request->allFiles()));
        
        try {
            Log::info('===== DEBUG: INICIO STORE (PROCEDURAL) =====');
            
            // Limpiar inputs numéricos
            $data = $request->all();
            foreach (['lat', 'lng', 'accuracy', 'monthly_price', 'area_m2', 'num_bedrooms', 'num_bathrooms'] as $field) {
                if (isset($data[$field]) && ($data[$field] === '' || $data[$field] === 'NaN' || $data[$field] === 'undefined' || $data[$field] === 'null')) {
                    Log::info("DEBUG: Limpiando {$field}");
                    $data[$field] = null;
                }
            }

            // Re-validar
            $validator = \Illuminate\Support\Facades\Validator::make($data, [
                'title'             => 'required|string|max:255',
                'description'       => 'required|string',
                'address'           => 'required|string',
                'city'              => 'nullable|string|max:120',
                'status'            => 'nullable|string|in:available,rented,maintenance',
                'monthly_price'     => 'required|numeric|min:0',
                'area_m2'           => 'nullable|numeric|min:0',
                'num_bedrooms'      => 'nullable|integer|min:0',
                'num_bathrooms'     => 'nullable|integer|min:0',
                'included_services' => 'nullable|string',
                'lat'               => 'nullable|numeric',
                'lng'               => 'nullable|numeric',
                'accuracy'          => 'nullable|numeric',
                'user_id'           => 'nullable|integer|exists:users,id',
                'images'            => 'nullable|array',
                'images.*'          => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'publication_date'  => 'nullable|date',
            ]);

            if ($validator->fails()) {
                Log::error('DEBUG: Errores de validación', $validator->errors()->toArray());
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();
            $user = $this->authUser();
            
            if (!$user) {
                Log::error('DEBUG: No hay usuario autenticado en el controlador');
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            DB::beginTransaction();
            Log::info('DEBUG: Transacción iniciada');

            // Normalización final
            $validated['included_services'] = $this->normalizeServices($request->input('included_services'));
            $validated['user_id'] = $request->input('user_id') ?: $user->id;
            $validated['publication_date'] = $request->input('publication_date') ?: now()->toDateString();
            
            // Forzar defaults para evitar NOT NULL constraints
            $validated['city'] = $validated['city'] ?? 'Popayán';
            $validated['status'] = $validated['status'] ?? 'available';
            $validated['area_m2'] = $validated['area_m2'] ?? 0;
            $validated['num_bedrooms'] = $validated['num_bedrooms'] ?? 0;
            $validated['num_bathrooms'] = $validated['num_bathrooms'] ?? 0;
            $validated['image_url'] = [];

            if ($this->isAdmin()) {
                $validated['approval_status'] = 'approved';
                $validated['visibility'] = 'published';
            }

            Log::info('DEBUG: Intentando Property::create', $validated);
            $property = Property::create($validated);
            Log::info("DEBUG: Propiedad creada ID: {$property->id}");

            if ($request->hasFile('images')) {
                Log::info('DEBUG: Procesando imágenes...');
                $disk = config('filesystems.default', 'public');
                foreach ($request->file('images') as $index => $file) {
                    $path = $file->store('properties', $disk);
                    PropertyImage::create([
                        'property_id' => $property->id,
                        'path'        => $path,
                        'is_main'     => $index === 0,
                        'order'       => $index,
                    ]);
                }
            }

            DB::commit();
            Log::info('DEBUG: Transacción commiteada');

            $property->load('user:id,name,email,phone,photo');

            return response()->json([
                'success' => true,
                'property' => $property,
                'execution_time' => microtime(true) - $startTime
            ], 201);

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            
            Log::error('CRASH EN STORE:', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error Fatal: ' . $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(), // Seguro para JSON
                'input'   => $request->except(['images']),
                'execution_time' => microtime(true) - $startTime
            ], 500);
        }
    }

    /**
     * Actualizar propiedad.
     */
    public function update(Request $request, Property $property): JsonResponse
    {
        $user = $this->authUser(); // ← una sola llamada, sin warning

        if ($property->user_id !== $user->id && !in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para editar esta propiedad',
            ], 403);
        }

        $validated = $request->validate([
            'title'             => 'sometimes|string|max:255',
            'description'       => 'sometimes|string',
            'address'           => 'sometimes|string',
            'city'              => 'sometimes|string|max:120',
            'status'            => 'sometimes|string|in:available,rented,maintenance',
            'monthly_price'     => 'sometimes|numeric|min:0',
            'area_m2'           => 'sometimes|numeric|min:0',
            'num_bedrooms'      => 'sometimes|integer|min:0',
            'num_bathrooms'     => 'sometimes|integer|min:0',
            'included_services' => 'sometimes|string',
            'lat'               => 'sometimes|numeric',
            'lng'               => 'sometimes|numeric',
            'images'            => 'sometimes|array',
            'images.*'          => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'delete_images'     => 'sometimes|array',
            'delete_images.*'   => 'integer|exists:property_images,id',
            'reorder_images'    => 'sometimes|array',
        ]);

        try {
            DB::beginTransaction();

            if (isset($validated['included_services'])) {
                $validated['included_services'] = $this->normalizeServices($validated['included_services']);
            }

            // 1. Eliminar imágenes por ID
            if (!empty($validated['delete_images'])) {
                $imagesToDelete = PropertyImage::whereIn('id', $validated['delete_images'])
                    ->where('property_id', $property->id)
                    ->get();
                
                $disk = config('filesystems.default', 'public');
                foreach ($imagesToDelete as $img) {
                    \Illuminate\Support\Facades\Storage::disk($disk)->delete($img->path);
                    $img->delete();
                }
            }

            // 2. Reordenar (Opcional, si se envía una lista de IDs en orden)
            // if (!empty($validated['reorder_images'])) { ... }

            // 3. Agregar nuevas imágenes
            if ($request->hasFile('images')) {
                $lastOrder = $property->images()->max('order') ?? -1;
                $disk = config('filesystems.default', 'public');
                foreach ($request->file('images') as $index => $file) {
                    $path = $file->store('properties', $disk);
                    PropertyImage::create([
                        'property_id' => $property->id,
                        'path'        => $path,
                        'is_main'     => false, // Podría mejorarse para elegir la principal
                        'order'       => $lastOrder + 1 + $index,
                    ]);
                }
            }

            unset($validated['images'], $validated['delete_images'], $validated['reorder_images']);
            
            // Actualizar is_main si no hay ninguna primaria
            if ($property->images()->where('is_main', true)->count() === 0) {
                $first = $property->images()->first();
                if ($first) {
                    $first->update(['is_main' => true]);
                }
            }

            unset($validated['images'], $validated['delete_images'], $validated['reorder_images']);

            $property->update($validated);

            DB::commit();

            $property->load(['user:id,name,email,phone,photo', 'images']);

            return response()->json([
                'success'  => true,
                'message'  => 'Propiedad actualizada correctamente',
                'property' => $property,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error actualizando propiedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la propiedad',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar propiedad.
     */
    public function destroy(Property $property): JsonResponse
    {
        $user = $this->authUser();

        if ($property->user_id !== $user->id && !in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar esta propiedad',
            ], 403);
        }

        try {
            DB::beginTransaction();
            $property->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Propiedad eliminada correctamente',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error eliminando propiedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la propiedad',
            ], 500);
        }
    }

    /**
     * Guardar/actualizar ubicación GPS.
     */
    public function savePoint(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'lat'      => 'required|numeric',
            'lng'      => 'required|numeric',
            'accuracy' => 'nullable|numeric',
        ]);

        $property = Property::findOrFail($id);
        $user     = $this->authUser();

        if ($property->user_id !== $user->id && !in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para actualizar la ubicación',
            ], 403);
        }

        // updateOnly para tocar exclusivamente los campos de geo, sin disparar eventos innecesarios
        $property->update([
            'lat'      => $validated['lat'],
            'lng'      => $validated['lng'],
            'accuracy' => $validated['accuracy'] ?? null,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Ubicación guardada correctamente',
            'property' => $property,
        ]);
    }

    /**
     * Incrementar vistas.
     */
    public function incrementViews(int $id): JsonResponse
    {
        // DB::increment evita cargar el modelo completo en memoria
        $updated = Property::where('id', $id)->increment('views');

        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Propiedad no encontrada'], 404);
        }

        $views = Property::where('id', $id)->value('views');

        return response()->json([
            'success' => true,
            'message' => 'Visita registrada',
            'views'   => $views,
        ]);
    }

    /**
     * Contar propiedades.
     */
    public function count(): JsonResponse
    {
        return response()->json([
            'count' => Property::count(),
        ]);
    }
}