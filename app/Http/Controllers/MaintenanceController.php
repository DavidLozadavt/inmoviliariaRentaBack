<?php

namespace App\Http\Controllers;

use App\Models\Maintenance;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MaintenanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $query = Maintenance::with(['property:id,title,address', 'user:id,name'])
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                  ->orWhereHas('property', fn($sub) => $sub->where('user_id', $userId));
            })
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 15);
        $maintenances = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $maintenances->items(),
            'meta'    => [
                'current_page' => $maintenances->currentPage(),
                'last_page'    => $maintenances->lastPage(),
                'per_page'     => $maintenances->perPage(),
                'total'        => $maintenances->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string|max:2000',
            'priority'    => 'required|string|in:low,medium,high,urgent',
            'property_id' => 'required|integer|exists:properties,id',
        ]);

        $property = Property::findOrFail($validated['property_id']);

        // Solo owner o tenant activo puede crear solicitudes
        $userId = Auth::id();
        $hasTenantContract = $property->contracts()
            ->where('tenant_id', $userId)
            ->where('status', 'active')
            ->exists();

        if ($property->user_id !== $userId && !$hasTenantContract) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para solicitar mantenimiento en esta propiedad',
            ], 403);
        }

        $validated['user_id']      = $userId;
        $validated['request_date'] = now()->toDateString();
        $validated['status']       = 'pending';

        $maintenance = Maintenance::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de mantenimiento creada exitosamente',
            'data'    => $maintenance->load(['property:id,title,address', 'user:id,name']),
        ], 201);
    }

    public function show(Maintenance $maintenance): JsonResponse
    {
        $userId = Auth::id();

        if ($maintenance->user_id !== $userId && $maintenance->property->user_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para ver esta solicitud',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $maintenance->load(['property:id,title,address', 'user:id,name']),
        ]);
    }

    public function update(Request $request, Maintenance $maintenance): JsonResponse
    {
        $userId = Auth::id();

        // Solo el dueño de la propiedad puede actualizar el estado
        if ($maintenance->property->user_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para actualizar esta solicitud',
            ], 403);
        }

        $validated = $request->validate([
            'status'              => 'sometimes|string|in:pending,in_progress,finished',
            'resolution_date'     => 'sometimes|nullable|date',
            'validated_by_tenant' => 'sometimes|nullable|string|in:yes,no',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'finished' && !isset($validated['resolution_date'])) {
            $validated['resolution_date'] = now()->toDateString();
        }

        $maintenance->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de mantenimiento actualizada',
            'data'    => $maintenance->fresh()->load(['property:id,title,address', 'user:id,name']),
        ]);
    }

    public function destroy(Maintenance $maintenance): JsonResponse
    {
        $userId = Auth::id();

        if ($maintenance->user_id !== $userId && $maintenance->property->user_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar esta solicitud',
            ], 403);
        }

        $maintenance->delete();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de mantenimiento eliminada',
        ]);
    }
}
