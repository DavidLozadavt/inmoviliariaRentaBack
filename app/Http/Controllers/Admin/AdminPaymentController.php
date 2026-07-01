<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class AdminPaymentController extends Controller
{
    /**
     * GET /api/admin/payments
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with([
            'contract:id,property_id,tenant_id,landlord_id',
            'contract.property:id,title,address',
            'contract.tenant:id,name,email',
            'contract.landlord:id,name,email',
        ])
        ->when($request->filled('status'), fn($q) => $q->where('status', $request->input('status')))
        ->when($request->filled('contract_id'), fn($q) => $q->where('contract_id', $request->input('contract_id')))
        ->when($request->filled('date_from'), fn($q) => $q->whereDate('created_at', '>=', $request->input('date_from')))
        ->when($request->filled('date_to'), fn($q) => $q->whereDate('created_at', '<=', $request->input('date_to')))
        ->orderBy('created_at', 'desc');

        $perPage  = (int) $request->input('per_page', 20);
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

    /**
     * GET /api/admin/payments/{payment}
     */
    public function show(Payment $payment): JsonResponse
    {
        $payment->load([
            'contract.property:id,title,address',
            'contract.tenant:id,name,email,phone',
            'contract.landlord:id,name,email,phone',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $payment,
        ]);
    }

    /**
     * PUT /api/admin/payments/{payment}/status
     */
    public function updateStatus(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,paid,rejected',
        ]);

        $previousStatus = $payment->status;
        $payment->update($validated);

        Log::info('Estado de pago actualizado por admin', [
            'payment_id'      => $payment->id,
            'admin_id'        => Auth::id(),
            'previous_status' => $previousStatus,
            'new_status'      => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Estado del pago actualizado',
            'data'    => $payment->fresh()->load('contract.property:id,title'),
        ]);
    }

    /**
     * GET /api/admin/payments/stats
     */
    public function stats(): JsonResponse
    {
        $stats = Payment::query()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_revenue,
                COALESCE(AVG(CASE WHEN status = 'paid' THEN amount END), 0) as avg_payment
            ")
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'         => (int) $stats->total,
                'paid'          => (int) $stats->paid,
                'pending'       => (int) $stats->pending,
                'rejected'      => (int) $stats->rejected,
                'total_revenue' => (float) $stats->total_revenue,
                'avg_payment'   => round((float) $stats->avg_payment, 2),
            ],
        ]);
    }
}
