<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminPropertyController extends Controller
{
    /**
     * Obtener estadísticas completas de propiedades
     *
     * GET /api/admin/properties/stats
     */
    public function stats()
    {
        try {
            $stats = [
                // Totales
                'total' => Property::count(),

                // Por estado de disponibilidad
                'available' => Property::where('status', 'available')->count(),
                'rented' => Property::where('status', 'rented')->count(),
                'maintenance' => Property::where('status', 'maintenance')->count(),

                // Por estado de aprobación
                'pending_approval' => Property::where('approval_status', 'pending')->count(),
                'approved' => Property::where('approval_status', 'approved')->count(),
                'rejected' => Property::where('approval_status', 'rejected')->count(),

                // Por visibilidad
                'published' => Property::where('visibility', 'published')->count(),
                'hidden' => Property::where('visibility', 'hidden')->count(),

                // Métricas adicionales
                'total_views' => Property::sum('views') ?? 0,
                'average_price' => round(Property::avg('monthly_price') ?? 0, 2),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'timestamp' => now()->toIso8601String()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar estado de aprobación de una propiedad
     *
     * PUT /api/admin/properties/{id}/approval
     */
    public function updateApproval(Request $request, $id)
    {
        $validated = $request->validate([
            'approval_status' => 'required|in:pending,approved,rejected'
        ]);

        try {
            $property = Property::findOrFail($id);

            // Guardar estado anterior para el log
            $previousStatus = $property->approval_status;

            // DEBUG: Log antes de actualizar
            Log::info('DEBUG: Before update', [
                'property_id' => $property->id,
                'current_approval_status' => $property->approval_status,
                'new_approval_status' => $validated['approval_status']
            ]);

            // Actualizar estado
            $updated = $property->update($validated);

            // DEBUG: Log después de update()
            Log::info('DEBUG: After update', [
                'updated' => $updated,
                'property_after_update' => $property->approval_status
            ]);

            // ¡IMPORTANTE! Refrescar la propiedad desde la base de datos
            $property->refresh();

            // DEBUG: Log después de refresh()
            Log::info('DEBUG: After refresh', [
                'property_after_refresh' => $property->approval_status
            ]);

            // Recargar con relaciones
            $property->load('user:id,name,email,phone,photo');

            // Log de la acción
            Log::info('Property approval status changed', [
                'property_id' => $property->id,
                'previous_status' => $previousStatus,
                'new_status' => $property->approval_status, // Usar $property->approval_status, no $validated
                'admin_user_id' => auth()->id(),
            ]);

            // TODO: Enviar notificación al propietario
            // event(new PropertyApprovalChanged($property));

            return response()->json([
                'success' => true,
                'message' => 'Estado de aprobación actualizado exitosamente',
                'property' => $property,
                'debug' => [
                    'previous_status' => $previousStatus,
                    'new_status' => $property->approval_status,
                    'updated_successfully' => $updated
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Propiedad no encontrada'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating property approval status', [
                'property_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar estado de aprobación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar visibilidad de una propiedad
     *
     * PUT /api/admin/properties/{id}/visibility
     */
    public function updateVisibility(Request $request, $id)
    {
        $validated = $request->validate([
            'visibility' => 'required|in:published,hidden'
        ]);

        try {
            $property = Property::findOrFail($id);

            // Guardar estado anterior para el log
            $previousVisibility = $property->visibility;

            // Actualizar visibilidad
            $property->update($validated);

            // Recargar con relaciones
            $property->load('user:id,name,email,phone,photo');

            // Log de la acción
            \Log::info('Property visibility changed', [
                'property_id' => $property->id,
                'previous_visibility' => $previousVisibility,
                'new_visibility' => $validated['visibility'],
                'admin_user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Visibilidad actualizada exitosamente',
                'property' => $property
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Propiedad no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar visibilidad',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Acción masiva sobre múltiples propiedades
     *
     * POST /api/admin/properties/bulk-action
     */
    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject,publish,hide,delete',
            'property_ids' => 'required|array|min:1',
            'property_ids.*' => 'required|integer|exists:properties,id'
        ]);

        try {
            DB::beginTransaction();

            $count = 0;
            $errors = [];

            foreach ($validated['property_ids'] as $propertyId) {
                try {
                    $property = Property::find($propertyId);

                    if (!$property) {
                        $errors[] = "Propiedad #{$propertyId} no encontrada";
                        continue;
                    }

                    switch ($validated['action']) {
                        case 'approve':
                            $property->update(['approval_status' => 'approved']);
                            break;
                        case 'reject':
                            $property->update(['approval_status' => 'rejected']);
                            break;
                        case 'publish':
                            $property->update(['visibility' => 'published']);
                            break;
                        case 'hide':
                            $property->update(['visibility' => 'hidden']);
                            break;
                        case 'delete':
                            $property->delete();
                            break;
                    }

                    $count++;
                } catch (\Exception $e) {
                    $errors[] = "Error en propiedad #{$propertyId}: " . $e->getMessage();
                }
            }

            DB::commit();

            // Log de la acción masiva
            \Log::info('Bulk action on properties', [
                'action' => $validated['action'],
                'total_properties' => count($validated['property_ids']),
                'successful' => $count,
                'failed' => count($errors),
                'admin_user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Acción completada: {$count} propiedades procesadas",
                'details' => [
                    'total' => count($validated['property_ids']),
                    'successful' => $count,
                    'failed' => count($errors),
                    'errors' => $errors
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al ejecutar acción masiva',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener propiedades pendientes de aprobación
     *
     * GET /api/admin/properties/pending
     */
    public function pending()
    {
        try {
            $properties = Property::where('approval_status', 'pending')
                ->with('user:id,name,email,phone,photo')
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $properties->items(),
                'meta' => [
                    'current_page' => $properties->currentPage(),
                    'last_page' => $properties->lastPage(),
                    'per_page' => $properties->perPage(),
                    'total' => $properties->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener propiedades pendientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de actividad reciente en propiedades
     *
     * GET /api/admin/properties/recent-activity
     */
    public function recentActivity()
    {
        try {
            // Últimas 10 propiedades creadas
            $recent = Property::with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($property) {
                    return [
                        'id' => $property->id,
                        'title' => $property->title,
                        'city' => $property->city,
                        'status' => $property->status,
                        'approval_status' => $property->approval_status,
                        'owner' => $property->user->name ?? 'Desconocido',
                        'created_at' => $property->created_at->toIso8601String(),
                        'days_ago' => $property->created_at->diffForHumans(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $recent
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener actividad reciente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
}
