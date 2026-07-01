<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = Contract::with(['property:id,title,address,city,monthly_price', 'tenant:id,name,email,phone', 'landlord:id,name,email,phone'])
            ->where(function ($q) use ($user) {
                $q->where('tenant_id', $user->id)
                  ->orWhere('landlord_id', $user->id);
            })
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 15);
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

    public function show(Contract $contract): JsonResponse
    {
        $user = Auth::user();

        if ($contract->tenant_id !== $user->id && $contract->landlord_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para ver este contrato',
            ], 403);
        }

        $contract->load([
            'property:id,title,address,city,monthly_price,image_url',
            'tenant:id,name,email,phone,photo',
            'landlord:id,name,email,phone,photo',
            'payments',
            'ratings',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $contract,
        ]);
    }

    public function accept(Contract $contract): JsonResponse
    {
        $user = Auth::user();

        if ($contract->tenant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Solo el inquilino puede aceptar este contrato',
            ], 403);
        }

        if ($contract->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Este contrato no está pendiente de aceptación',
            ], 400);
        }

        $contract->update([
            'status'                 => 'active',
            'accepted_by_tenant'     => true,
            'tenant_acceptance_date' => now(),
        ]);

        // Actualizar propiedad a rentada
        $contract->property?->update(['status' => 'rented']);

        // Notificar al landlord
        Notification::create([
            'user_id' => $contract->landlord_id,
            'type'    => 'contract_accepted',
            'title'   => 'Contrato aceptado',
            'message' => "El inquilino ha aceptado el contrato para {$contract->property->title}",
            'data'    => ['contract_id' => $contract->id],
        ]);

        Log::info('Contrato aceptado', [
            'contract_id' => $contract->id,
            'tenant_id'   => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contrato aceptado exitosamente',
            'data'    => $contract->fresh()->load(['property:id,title', 'tenant:id,name', 'landlord:id,name']),
        ]);
    }

    public function reject(Contract $contract): JsonResponse
    {
        $user = Auth::user();

        if ($contract->tenant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Solo el inquilino puede rechazar este contrato',
            ], 403);
        }

        if ($contract->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Este contrato no está pendiente',
            ], 400);
        }

        $contract->update(['status' => 'rejected']);

        Notification::create([
            'user_id' => $contract->landlord_id,
            'type'    => 'contract_rejected',
            'title'   => 'Contrato rechazado',
            'message' => "El inquilino ha rechazado el contrato para {$contract->property->title}",
            'data'    => ['contract_id' => $contract->id],
        ]);

        Log::info('Contrato rechazado', [
            'contract_id' => $contract->id,
            'tenant_id'   => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contrato rechazado',
            'data'    => $contract->fresh(),
        ]);
    }

    public function stats(): JsonResponse
    {
        $user = Auth::user();

        $contracts = Contract::where('tenant_id', $user->id)
            ->orWhere('landlord_id', $user->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'total'    => (clone $contracts)->count(),
                'active'   => (clone $contracts)->where('status', 'active')->count(),
                'pending'  => (clone $contracts)->where('status', 'pending')->count(),
                'expired'  => (clone $contracts)->where('status', 'expired')->count(),
                'rejected' => (clone $contracts)->where('status', 'rejected')->count(),
            ],
        ]);
    }
}
