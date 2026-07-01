<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    /**
     * GET /api/admin/reports
     */
    public function index(Request $request): JsonResponse
    {
        $query = Report::with(['user:id,name,email'])
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->input('user_id')))
            ->when($request->filled('search'), fn($q) =>
                $q->where('type', 'LIKE', "%{$request->input('search')}%")
                  ->orWhere('applied_filter', 'LIKE', "%{$request->input('search')}%")
            )
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 20);
        $reports = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $reports->items(),
            'meta'    => [
                'current_page' => $reports->currentPage(),
                'last_page'    => $reports->lastPage(),
                'per_page'     => $reports->perPage(),
                'total'        => $reports->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/reports/{report}
     */
    public function show(Report $report): JsonResponse
    {
        $report->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'data'    => $report,
        ]);
    }

    /**
     * DELETE /api/admin/reports/{report}
     */
    public function destroy(Report $report): JsonResponse
    {
        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Reporte eliminado exitosamente',
        ]);
    }

    /**
     * PUT /api/admin/reports/{report}/status
     */
    public function updateStatus(Request $request, Report $report): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,reviewed,resolved,dismissed',
        ]);

        $oldStatus = $report->status;
        $newStatus = $validated['status'];

        $report->update(['status' => $newStatus]);

        // Mapeo simple a español para el mensaje
        $statusLabels = [
            'pending' => 'Pendiente',
            'reviewed' => 'Revisado',
            'resolved' => 'Resuelto',
            'dismissed' => 'Descartado'
        ];
        
        $statusEs = $statusLabels[$newStatus] ?? $newStatus;

        // Crear notificación para el usuario que creó el reporte
        Notification::create([
            'user_id' => $report->user_id,
            'type'    => 'report_status_changed',
            'title'   => 'Actualización de Reporte',
            'message' => "El estado de tu reporte #{$report->id} ha cambiado a: {$statusEs}.",
            'data'    => [
                'report_id'  => $report->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'type'       => $report->type
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado del reporte actualizado con éxito',
            'data'    => $report->fresh(),
        ]);
    }

    /**
     * GET /api/admin/reports/stats
     */
    public function stats(): JsonResponse
    {
        $total = Report::count();
        $pending = Report::where('status', 'pending')->count();
        $propertyFlags = Report::whereIn('type', ['property', 'properties', 'propiedad'])->count();
        $userFlags = Report::whereIn('type', ['user', 'users', 'usuario'])->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_reports'    => $total,
                'pending_reports'  => $pending,
                'property_reports' => $propertyFlags,
                'user_reports'     => $userFlags,
            ],
        ]);
    }
}
