<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $query = Payment::with(['contract.property:id,title,address'])
            ->whereHas('contract', function ($q) use ($userId) {
                $q->where('tenant_id', $userId)->orWhere('landlord_id', $userId);
            })
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('contract_id'), fn($q) => $q->where('contract_id', $request->input('contract_id')))
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 15);
        $payments = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $payments->items(),
            'meta'    => [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id'    => 'required|integer|exists:contracts,id',
            'amount'         => 'required|numeric|min:0',
            'payment_method' => 'required|string|in:transfer,cash,card,pse',
            'payment_date'   => 'nullable|date',
            'receipt_path'   => 'nullable|string',
        ]);

        $userId = Auth::id();
        $contract = Contract::findOrFail($validated['contract_id']);

        // Solo el tenant puede registrar pagos
        if ($contract->tenant_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para registrar pagos en este contrato',
            ], 403);
        }

        if ($contract->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden registrar pagos en contratos activos',
            ], 400);
        }

        $validated['status']       = 'pending';
        $validated['payment_date'] = $validated['payment_date'] ?? now()->toDateString();

        $payment = Payment::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Pago registrado exitosamente',
            'data'    => $payment->load('contract.property:id,title'),
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $userId = Auth::id();

        $payment->load('contract');
        if ($payment->contract->tenant_id !== $userId && $payment->contract->landlord_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para ver este pago',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $payment->load('contract.property:id,title,address'),
        ]);
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,paid,rejected',
        ]);

        $userId = Auth::id();
        $payment->load('contract');

        // Solo el landlord puede confirmar/rechazar pagos
        if ($payment->contract->landlord_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para actualizar este pago',
            ], 403);
        }

        $payment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Pago actualizado exitosamente',
            'data'    => $payment->fresh()->load('contract.property:id,title'),
        ]);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $userId = Auth::id();
        $payment->load('contract');

        if ($payment->contract->tenant_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar este pago',
            ], 403);
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden eliminar pagos confirmados',
            ], 400);
        }

        $payment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pago eliminado exitosamente',
        ]);
    }

    public function processSimulation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id'    => 'required|integer|exists:contracts,id',
            'amount'         => 'required|numeric|min:0',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            // Or if they are passing raw card info (simulate saving it)
            'card_last_four' => 'nullable|string|size:4',
            'card_type'      => 'nullable|string',
        ]);

        $userId = Auth::id();
        $contract = Contract::findOrFail($validated['contract_id']);

        // Only tenant can pay
        if ($contract->tenant_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para realizar pagos en este contrato',
            ], 403);
        }

        // Allow payment if status is pending (pendiente por pago) or active
        if (!in_array($contract->status, ['pending', 'active'])) {
            return response()->json([
                'success' => false,
                'message' => 'Este contrato no admite pagos en su estado actual',
            ], 400);
        }

        // Create the payment record
        $payment = Payment::create([
            'contract_id'    => $contract->id,
            'amount'         => $validated['amount'],
            'status'         => 'paid',
            'payment_date'   => now()->toDateString(),
            'payment_method' => $validated['card_type'] ?? 'card',
            'receipt_path'  => 'simulated_receipt_' . time() . '.pdf',
        ]);

// El estado del contrato se actualizará mediante el endpoint /accept en el frontend
        // para asegurar que se ejecute toda la lógica de aceptación (notificaciones, etc.)
        // $contract->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Pago procesado exitosamente',
            'data'    => $payment->load('contract.property:id,title'),
        ]);
    }
}
