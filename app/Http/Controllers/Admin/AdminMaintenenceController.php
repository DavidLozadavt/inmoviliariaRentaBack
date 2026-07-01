<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maintenance;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminMaintenenceController extends Controller
{
    /**
     * GET /api/admin/maintenances
     */
    public function index(Request $request): JsonResponse
    {
        $query = Maintenance::with([
            'property:id,title,address,city,user_id',
            'property.user:id,name,email',
            'user:id,name,email',
        ])
        ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
        ->when($request->filled('property_id'), fn($q) => $q->where('property_id', $request->input('property_id')))
        ->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->input('search');
            $q->where('description', 'LIKE', "%{$search}%")
              ->orWhereHas('property', fn($sub) => $sub->where('title', 'LIKE', "%{$search}%"));
        })
        ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 20);
        $items   = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $items->items(),
            'meta'    => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/maintenances/{maintenance}
     */
    public function show(Maintenance $maintenance): JsonResponse
    {
        $maintenance->load([
            'property:id,title,address,city,user_id',
            'property.user:id,name,email,phone',
            'user:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $maintenance,
        ]);
    }

    /**
     * PUT /api/admin/maintenances/{maintenance}/status
     */
    public function updateStatus(Request $request, Maintenance $maintenance): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,finished',
        ]);

        $previousStatus = $maintenance->status;

        $updateData = ['status' => $validated['status']];

        if ($validated['status'] === 'finished') {
            $updateData['resolution_date'] = now()->toDateString();
        }

        $maintenance->update($updateData);

        // Notificar al solicitante
        Notification::create([
            'user_id' => $maintenance->user_id,
            'type'    => 'maintenance_status_updated',
            'title'   => 'Actualización de mantenimiento',
            'message' => "El estado de tu solicitud de mantenimiento cambió de '{$previousStatus}' a '{$validated['status']}'",
            'data'    => [
                'maintenance_id' => $maintenance->id,
                'previous_status' => $previousStatus,
                'new_status' => $validated['status'],
            ],
        ]);

        Log::info('Estado de mantenimiento actualizado por admin', [
            'maintenance_id'  => $maintenance->id,
            'admin_id'        => Auth::id(),
            'previous_status' => $previousStatus,
            'new_status'      => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado de mantenimiento actualizado',
            'data'    => $maintenance->fresh()->load(['property:id,title', 'user:id,name']),
        ]);
    }

    /**
     * GET /api/admin/maintenances/stats
     */
    public function stats(): JsonResponse
    {
        $stats = Maintenance::query()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) as finished
            ")
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'       => (int) $stats->total,
                'pending'     => (int) $stats->pending,
                'in_progress' => (int) $stats->in_progress,
                'finished'    => (int) $stats->finished,
            ],
        ]);
    }
}
