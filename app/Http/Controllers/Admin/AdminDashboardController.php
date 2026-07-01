<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Property;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Maintenance;
use App\Models\Report;
use App\Services\ActivityService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    private function calculateGrowth($current, $previous) {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    public function getStats(): JsonResponse
    {
        try {
            $now = Carbon::now();
            $currentMonthStart = $now->copy()->startOfMonth();
            $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
            $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

            // ── USUARIOS ──────────────────────────
            $userStats = User::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending_verification,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as current_month,
                    SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) as last_month
                ", [$currentMonthStart, $lastMonthStart, $lastMonthEnd])
                ->first();

            // ── PROPIEDADES ───────────────────────
            $propertyStats = Property::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                    SUM(CASE WHEN status = 'rented' THEN 1 ELSE 0 END) as rented,
                    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
                    SUM(CASE WHEN approval_status = 'pending' THEN 1 ELSE 0 END) as pending_approval,
                    SUM(CASE WHEN approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN visibility = 'published' THEN 1 ELSE 0 END) as published,
                    SUM(CASE WHEN visibility = 'hidden' THEN 1 ELSE 0 END) as hidden,
                    COALESCE(SUM(views), 0) as total_views,
                    COALESCE(ROUND(AVG(monthly_price), 2), 0) as average_price,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as current_month,
                    SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) as last_month
                ", [$currentMonthStart, $lastMonthStart, $lastMonthEnd])
                ->first();

            // ── CONTRATOS ─────────────────────────
            $contractStats = Contract::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as current_month,
                    SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) as last_month
                ", [$currentMonthStart, $lastMonthStart, $lastMonthEnd])
                ->first();

            // ── PAGOS ─────────────────────────────
            $paymentStats = Payment::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as revenue,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND payment_date >= ? THEN amount ELSE 0 END), 0) as current_month_revenue,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND payment_date >= ? AND payment_date <= ? THEN amount ELSE 0 END), 0) as last_month_revenue
                ", [$currentMonthStart, $lastMonthStart, $lastMonthEnd])
                ->first();

            // ── SOLICITUDES ───────────────────────
            $requestStats = RentalRequest::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                ")
                ->first();

            // ── MANTENIMIENTOS ────────────────────
            $maintenanceStats = Maintenance::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) as finished
                ")
                ->first();

            // ── REVENUE CHART (LAST 6 MONTHS) ──────
            $chartLabels = [];
            $chartData = [];
            
            for ($i = 5; $i >= 0; $i--) {
                $monthStart = Carbon::now()->subMonths($i)->startOfMonth();
                $monthEnd   = Carbon::now()->subMonths($i)->endOfMonth();
                
                // Mapeo manual simple para meses en español
                $mesesEs = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                $monthName = $mesesEs[$monthStart->month - 1] . " " . $monthStart->format('y');
                
                $revenue = Payment::where('status', 'paid')
                    ->whereBetween('payment_date', [$monthStart, $monthEnd])
                    ->sum('amount');
                    
                $chartLabels[] = $monthName;
                $chartData[] = (float) $revenue;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'users' => [
                        'total'                => (int) $userStats->total,
                        'active'               => (int) $userStats->active,
                        'inactive'             => (int) $userStats->inactive,
                        'pending_verification' => (int) $userStats->pending_verification,
                        'growth'               => $this->calculateGrowth((int)$userStats->current_month, (int)$userStats->last_month)
                    ],
                    'properties' => [
                        'total'            => (int) $propertyStats->total,
                        'available'        => (int) $propertyStats->available,
                        'rented'           => (int) $propertyStats->rented,
                        'maintenance'      => (int) $propertyStats->maintenance,
                        'pending_approval' => (int) $propertyStats->pending_approval,
                        'approved'         => (int) $propertyStats->approved,
                        'rejected'         => (int) $propertyStats->rejected,
                        'published'        => (int) $propertyStats->published,
                        'hidden'           => (int) $propertyStats->hidden,
                        'total_views'      => (int) $propertyStats->total_views,
                        'average_price'    => (float) $propertyStats->average_price,
                        'growth'           => $this->calculateGrowth((int)$propertyStats->current_month, (int)$propertyStats->last_month)
                    ],
                    'contracts' => [
                        'total'    => (int) $contractStats->total,
                        'active'   => (int) $contractStats->active,
                        'pending'  => (int) $contractStats->pending,
                        'expired'  => (int) $contractStats->expired,
                        'rejected' => (int) $contractStats->rejected,
                        'growth'   => $this->calculateGrowth((int)$contractStats->current_month, (int)$contractStats->last_month)
                    ],
                    'payments' => [
                        'total'    => (int) $paymentStats->total,
                        'paid'     => (int) $paymentStats->paid,
                        'pending'  => (int) $paymentStats->pending,
                        'rejected' => (int) $paymentStats->rejected,
                        'revenue'  => (float) $paymentStats->current_month_revenue, // Ingresos de este mes
                        'total_revenue' => (float) $paymentStats->revenue,
                        'growth'   => $this->calculateGrowth((float)$paymentStats->current_month_revenue, (float)$paymentStats->last_month_revenue)
                    ],
                    'requests' => [
                        'total'    => (int) $requestStats->total,
                        'pending'  => (int) $requestStats->pending,
                        'accepted' => (int) $requestStats->accepted,
                        'rejected' => (int) $requestStats->rejected,
                    ],
                    'maintenances' => [
                        'total'       => (int) $maintenanceStats->total,
                        'pending'     => (int) $maintenanceStats->pending,
                        'in_progress' => (int) $maintenanceStats->in_progress,
                        'finished'    => (int) $maintenanceStats->finished,
                    ],
                    'charts' => [
                        'revenue' => [
                            'labels' => $chartLabels,
                            'data' => $chartData
                        ]
                    ]
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error obteniendo estadísticas del dashboard', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error'   => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
            ], 500);
        }
    }

    public function getRecentActivity(Request $request): JsonResponse
    {
        try {
            $limit = min(max((int) $request->input('limit', 10), 1), 50);

            $activities = collect();

            $cachedActivities = ActivityService::getRecentActivities($limit);
            foreach ($cachedActivities as $activity) {
                $activities->push($activity);
            }

            if ($activities->count() < $limit) {
                $recentUsers = User::select('id', 'name', 'created_at')
                    ->orderBy('created_at', 'desc')->take($limit)->get();

                foreach ($recentUsers as $user) {
                    $exists = $activities->contains(fn($a) => ($a['type'] ?? '') === 'user_registered' && ($a['data']['user_id'] ?? null) === $user->id);
                    if (!$exists) {
                        $activities->push([
                            'id'         => 'user_' . $user->id,
                            'type'       => 'user_registered',
                            'data'       => ['description' => "{$user->name} se registró en la plataforma."],
                            'created_at' => $user->created_at->toISOString(),
                            'timestamp'  => $user->created_at->timestamp,
                        ]);
                    }
                }
            }

            $recentProperties = Property::with('user')->orderBy('created_at', 'desc')->take(5)->get();
            foreach ($recentProperties as $property) {
                $exists = $activities->contains(fn($a) => ($a['type'] ?? '') === 'property_created' && ($a['data']['property_id'] ?? null) === $property->id);
                if (!$exists) {
                    $ownerName = $property->user ? $property->user->name : 'Un usuario';
                    $activities->push([
                        'id'         => 'property_' . $property->id,
                        'type'       => 'property_created',
                        'data'       => ['description' => "{$ownerName} publicó la propiedad '{$property->title}'."],
                        'created_at' => $property->created_at->toISOString(),
                        'timestamp'  => $property->created_at->timestamp,
                    ]);
                }
            }

            $recentContracts = Contract::with(['property', 'tenant'])->orderBy('created_at', 'desc')->take(5)->get();
            foreach ($recentContracts as $contract) {
                $tenantName = $contract->tenant ? $contract->tenant->name : 'Un usuario';
                $propTitle = $contract->property ? $contract->property->title : 'una propiedad';
                $activities->push([
                    'id'         => 'contract_' . $contract->id,
                    'type'       => 'contract_signed',
                    'data'       => ['description' => "{$tenantName} registró un contrato para '{$propTitle}'."],
                    'created_at' => $contract->created_at->toISOString(),
                    'timestamp'  => $contract->created_at->timestamp,
                ]);
            }

            $recentPayments = Payment::with('contract.tenant')->where('status', 'paid')->orderBy('payment_date', 'desc')->take(5)->get();
            foreach ($recentPayments as $payment) {
                $tenantName = ($payment->contract && $payment->contract->tenant) ? $payment->contract->tenant->name : 'Un usuario';
                $amount = number_format($payment->amount, 0, ',', '.');
                $activities->push([
                    'id'         => 'payment_' . $payment->id,
                    'type'       => 'payment_received',
                    'data'       => ['description' => "{$tenantName} realizó un pago de $\$$amount."],
                    'created_at' => $payment->created_at->toISOString(),
                    'timestamp'  => $payment->created_at->timestamp,
                ]);
            }

            $sorted = $activities->sortByDesc('timestamp')->take($limit)->values()->toArray();

            return response()->json([
                'success' => true,
                'data'    => $sorted,
            ]);
        } catch (\Exception $e) {
            Log::error('Error obteniendo actividad reciente', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener actividad reciente',
                'data'    => [],
            ], 500);
        }
    }
}

