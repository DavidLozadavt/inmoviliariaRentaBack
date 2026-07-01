<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminContractController extends Controller
{
    /**
     * GET /api/admin/contracts
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contract::with([
            'property:id,title,address,city,monthly_price',
            'tenant:id,name,email,phone',
            'landlord:id,name,email,phone',
        ])
        ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
        ->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->input('search');
            $q->whereHas('tenant', fn($sub) => $sub->where('name', 'LIKE', "%{$search}%"))
              ->orWhereHas('landlord', fn($sub) => $sub->where('name', 'LIKE', "%{$search}%"))
              ->orWhereHas('property', fn($sub) => $sub->where('title', 'LIKE', "%{$search}%"));
        })
        ->orderBy('created_at', 'desc');

        $perPage   = (int) $request->input('per_page', 20);
        $contracts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $contracts->items(),
            'meta'    => [
                'current_page' => $contracts->currentPage(),
                'last_page'    => $contracts->lastPage(),
                'per_page'     => $contracts->perPage(),
                'total'        => $contracts->total(),
            ],
        ]);
    }

    /**
     * GET /api/admin/contracts/{contract}
     */
    public function show(Contract $contract): JsonResponse
    {
        $contract->load([
            'property',
            'tenant:id,name,email,phone,photo',
            'landlord:id,name,email,phone,photo',
            'payments',
            'ratings.user:id,name',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $contract,
        ]);
    }

    /**
     * PUT /api/admin/contracts/{contract}/validate
     * Admin valida/verifica un contrato.
     */
    public function validateContract(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'validated_by_support' => 'required|boolean',
        ]);

        $contract->update([
            'validated_by_support'   => $validated['validated_by_support'],
            'support_validation_date' => now(),
        ]);

        // Notificar a ambas partes
        Notification::create([
            'user_id' => $contract->tenant_id,
            'type'    => 'contract_validated',
            'title'   => 'Contrato verificado',
            'message' => $validated['validated_by_support']
                ? 'Tu contrato ha sido verificado por el equipo de soporte'
                : 'Tu contrato no pasó la verificación. Contacta a soporte',
            'data'    => ['contract_id' => $contract->id],
        ]);

        Notification::create([
            'user_id' => $contract->landlord_id,
            'type'    => 'contract_validated',
            'title'   => 'Contrato verificado',
            'message' => $validated['validated_by_support']
                ? 'El contrato ha sido verificado por el equipo de soporte'
                : 'El contrato no pasó la verificación. Contacta a soporte',
            'data'    => ['contract_id' => $contract->id],
        ]);

        Log::info('Contrato verificado por admin', [
            'contract_id' => $contract->id,
            'admin_id'    => Auth::id(),
            'validated'   => $validated['validated_by_support'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contrato verificado exitosamente',
            'data'    => $contract->fresh()->load(['property:id,title', 'tenant:id,name', 'landlord:id,name']),
        ]);
    }

    /**
     * PUT /api/admin/contracts/{contract}/cancel
     */
    public function cancel(Contract $contract): JsonResponse
    {
        if (in_array($contract->status, ['cancelled', 'expired'])) {
            return response()->json([
                'success' => false,
                'message' => 'Este contrato ya no puede ser cancelado',
            ], 400);
        }

        $previousStatus = $contract->status;
        $contract->update(['status' => 'cancelled']);

        // Restaurar propiedad a disponible
        $contract->property?->update(['status' => 'available']);

        // Notificar a ambas partes
        foreach ([$contract->tenant_id, $contract->landlord_id] as $userId) {
            Notification::create([
                'user_id' => $userId,
                'type'    => 'contract_cancelled',
                'title'   => 'Contrato cancelado',
                'message' => 'El contrato ha sido cancelado por administración',
                'data'    => ['contract_id' => $contract->id, 'previous_status' => $previousStatus],
            ]);
        }

        Log::info('Contrato cancelado por admin', [
            'contract_id'     => $contract->id,
            'admin_id'        => Auth::id(),
            'previous_status' => $previousStatus,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contrato cancelado exitosamente',
            'data'    => $contract->fresh(),
        ]);
    }

    /**
     * GET /api/admin/contracts/stats
     */
    public function stats(): JsonResponse
    {
        $stats = Contract::query()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN validated_by_support = 1 THEN 1 ELSE 0 END) as validated
            ")
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => (int) $stats->total,
                'active'    => (int) $stats->active,
                'pending'   => (int) $stats->pending,
                'expired'   => (int) $stats->expired,
                'cancelled' => (int) $stats->cancelled,
                'rejected'  => (int) $stats->rejected,
                'validated' => (int) $stats->validated,
            ],
        ]);
    }
}
