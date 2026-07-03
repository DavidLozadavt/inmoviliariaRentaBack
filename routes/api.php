<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CaptureSessionController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\LumaWebhookController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\ModelStatusController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\RentalRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;

use App\Http\Controllers\Admin\AdminContractController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminMaintenenceController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminPropertyController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminViewController;

/*
|--------------------------------------------------------------------------
| Rutas Públicas
|--------------------------------------------------------------------------
*/

// TEST ROUTE - Hitting http://api.rentus/api/v1-test should return "API OK"
Route::get('v1-test', function () {
    return response()->json(['message' => 'API OK']);
});

// Propiedades públicas (listado y detalle)
Route::prefix('properties')->group(function () {
    Route::get('/', [PropertyController::class, 'index']);
    Route::get('count', [PropertyController::class, 'count']);
    Route::get('{property}', [PropertyController::class, 'show']);
    Route::post('{id}/views', [PropertyController::class, 'incrementViews']);
});

// Proxy de Geocoding (Evita CORS en producción)
Route::get('geocoding/search', [\App\Http\Controllers\GeocodingController::class, 'search']);

// Lyra Cognitive System (Brain of RentUs)
Route::prefix('lyra')->middleware('throttle:120,1')->group(function () {
    Route::post('interpret', [\App\Http\Controllers\LyraController::class, 'interpret']);
    Route::post('execute', [\App\Http\Controllers\LyraController::class, 'execute']);
    Route::get('context', [\App\Http\Controllers\LyraController::class, 'context']);
    Route::get('status', [\App\Http\Controllers\LyraController::class, 'status']);
});

// Deprecated Voice Assistant route (Keeping for transition if needed, but Lyra is the new standard)
Route::post('voice/interpret', [\App\Http\Controllers\VoiceController::class, 'interpret'])
    ->middleware('throttle:10,1');
// Webhook de Luma AI (público, sin autenticación)
// Luma NO provee firma HMAC — ver LumaWebhookController para detalles
Route::post('webhooks/luma', [LumaWebhookController::class, 'handle']);

// Puente de integración ERP → RentUs (público, protegido por X-Bridge-Token)
Route::post('integration/inscribe-inmobiliaria', [\App\Http\Controllers\RentUsBridgeController::class, 'inscribirInmobiliaria']);
Route::post('integration/sync-password', [\App\Http\Controllers\RentUsBridgeController::class, 'syncPassword']);

/*
|--------------------------------------------------------------------------
| Autenticación (con throttle)
|--------------------------------------------------------------------------
|*/
Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('check-token', [AuthController::class, 'checkToken']);
    Route::post('resend-code', [AuthController::class, 'resendVerificationCode']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| Rutas Protegidas (requieren JWT)
|--------------------------------------------------------------------------
|*/
// Debug & Storage Testing
Route::post('/test-upload', [\App\Http\Controllers\FileUploadController::class, 'upload'])->middleware('auth:api');

Route::middleware('auth:api')->group(function () {

    // ── Auth (usuario autenticado) ──────────────
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('update-password', [AuthController::class, 'updatePassword']);
    });

    // ── Usuarios ────────────────────────────────
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::get('stats', [UserController::class, 'getStats']);
        Route::get('{id}', [UserController::class, 'show']);
        Route::post('/', [UserController::class, 'store']);
        Route::put('{id}', [UserController::class, 'update']);
        Route::delete('{id}', [UserController::class, 'destroy']);
        Route::patch('{id}/status', [UserController::class, 'updateStatus']);
    });

    // ── Métodos de Pago (Movidos arriba para prioridad) ──────────
    Route::get('payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('payment-methods', [PaymentMethodController::class, 'store']);
    Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy']);

    // ── Propiedades (CRUD autenticado) ──────────
    Route::prefix('properties')->group(function () {
        Route::post('/', [PropertyController::class, 'store']);
        Route::match(['put', 'post'], '{property}', [PropertyController::class, 'update']);
        Route::delete('{property}', [PropertyController::class, 'destroy']);
        Route::post('{id}/save-point', [PropertyController::class, 'savePoint']);
        Route::get('{property}/3d-status', [ModelStatusController::class, 'show']);
    });

    // ── Sesiones de captura 3D ───────────────────
    Route::prefix('capture-sessions')->group(function () {
        Route::post('/', [CaptureSessionController::class, 'store']);
        Route::post('presigned-url', [CaptureSessionController::class, 'presignedUrl']);
        Route::post('confirm-upload', [CaptureSessionController::class, 'confirmPresignedUpload']);
        Route::post('upload-direct', [CaptureSessionController::class, 'uploadDirect']);
        Route::get('{token}', [CaptureSessionController::class, 'show']);
        Route::patch('{token}/status', [CaptureSessionController::class, 'updateStatus']);
    });

    // ── Solicitudes de arriendo ─────────────────
    Route::prefix('rental-requests')->group(function () {
        Route::get('my-requests', [RentalRequestController::class, 'getMyRequests']);
        Route::get('my-received', [RentalRequestController::class, 'getOwnerRequests']);
        Route::post('/', [RentalRequestController::class, 'create']);
        Route::put('{id}/accept', [RentalRequestController::class, 'acceptRequest']);
        Route::put('{id}/reject', [RentalRequestController::class, 'rejectRequest']);
        Route::put('{id}/counter', [RentalRequestController::class, 'counterPropose']);
        Route::put('{id}/accept-counter', [RentalRequestController::class, 'acceptCounterProposal']);
        Route::put('{id}/reject-counter', [RentalRequestController::class, 'rejectCounterProposal']);
        Route::get('{id}/visit-status', [RentalRequestController::class, 'checkVisitStatus']);
        Route::post('send-contract', [RentalRequestController::class, 'sendContractTerms']);
        Route::get('{id}', [RentalRequestController::class, 'getDetails']);
        Route::put('{id}/cancel', [RentalRequestController::class, 'cancel']);
    });

    // ── Contratos ───────────────────────────────
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index']);
        Route::get('stats', [ContractController::class, 'stats']);
        Route::get('{contract}', [ContractController::class, 'show']);
        Route::put('{contract}/accept', [ContractController::class, 'accept']);
        Route::put('{contract}/reject', [ContractController::class, 'reject']);
    });

    // ── Pagos ───────────────────────────────────
    Route::post('payments/simulate', [PaymentController::class, 'processSimulation']);
    Route::apiResource('payments', PaymentController::class);

    // ── Calificaciones ──────────────────────────
    Route::apiResource('ratings', RatingController::class);

    // ── Reportes ────────────────────────────────
    Route::apiResource('reports', ReportController::class);

    // ── Mantenimientos ──────────────────────────
    Route::apiResource('maintenances', MaintenanceController::class);

    // ── Notificaciones ──────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::put('{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::put('read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('{notification}', [NotificationController::class, 'destroy']);
    });

    /*
    |----------------------------------------------------------------------
    | Panel de Administración (admin + support)
    |----------------------------------------------------------------------
    |*/
    Route::prefix('admin')->middleware('role:admin,support')->group(function () {

        // ── Dashboard ────────────────────────
        Route::prefix('dashboard')->group(function () {
            Route::get('stats', [AdminDashboardController::class, 'getStats']);
            Route::get('activity', [AdminDashboardController::class, 'getRecentActivity']);
        });

        // ── Gestión de Usuarios (admin reutiliza UserController) ──
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('stats', [UserController::class, 'getStats']);
            Route::get('{id}', [UserController::class, 'show']);
            Route::post('/', [UserController::class, 'store']);
            Route::put('{id}', [UserController::class, 'update']);
            Route::delete('{id}', [UserController::class, 'destroy']);
            Route::patch('{id}/status', [UserController::class, 'updateStatus']);
        });

        // ── Gestión de Propiedades ────────────
        Route::prefix('properties')->group(function () {
            Route::get('stats', [AdminPropertyController::class, 'stats']);
            Route::get('pending', [AdminPropertyController::class, 'pending']);
            Route::get('recent-activity', [AdminPropertyController::class, 'recentActivity']);
            Route::put('{id}/approval', [AdminPropertyController::class, 'updateApproval']);
            Route::put('{id}/visibility', [AdminPropertyController::class, 'updateVisibility']);
            Route::post('bulk-action', [AdminPropertyController::class, 'bulkAction']);
        });

        // ── Gestión de Contratos ──────────────
        Route::prefix('contracts')->group(function () {
            Route::get('/', [AdminContractController::class, 'index']);
            Route::get('stats', [AdminContractController::class, 'stats']);
            Route::get('{contract}', [AdminContractController::class, 'show']);
            Route::put('{contract}/validate', [AdminContractController::class, 'validateContract']);
            Route::put('{contract}/cancel', [AdminContractController::class, 'cancel']);
        });

        // ── Gestión de Pagos ──────────────────
        Route::prefix('payments')->group(function () {
            Route::get('/', [AdminPaymentController::class, 'index']);
            Route::get('stats', [AdminPaymentController::class, 'stats']);
            Route::get('{payment}', [AdminPaymentController::class, 'show']);
            Route::put('{payment}/status', [AdminPaymentController::class, 'updateStatus']);
        });

        // ── Gestión de Mantenimientos ─────────
        Route::prefix('maintenances')->group(function () {
            Route::get('/', [AdminMaintenenceController::class, 'index']);
            Route::get('stats', [AdminMaintenenceController::class, 'stats']);
            Route::get('{maintenance}', [AdminMaintenenceController::class, 'show']);
            Route::put('{maintenance}/status', [AdminMaintenenceController::class, 'updateStatus']);
        });

        // ── Gestión de Reportes ───────────────
        Route::prefix('reports')->group(function () {
            Route::get('/', [AdminReportController::class, 'index']);
            Route::get('stats', [AdminReportController::class, 'stats']);
            Route::get('{report}', [AdminReportController::class, 'show']);
            Route::put('{report}/status', [AdminReportController::class, 'updateStatus']);
            Route::delete('{report}', [AdminReportController::class, 'destroy']);
        });

        // ── Estadísticas de Vistas ────────────
        Route::prefix('views')->group(function () {
            Route::get('stats', [AdminViewController::class, 'stats']);
            Route::get('top-properties', [AdminViewController::class, 'topProperties']);
            Route::get('by-city', [AdminViewController::class, 'viewsByCity']);
        });

        // ── Gestión de Lyra AI ────────────────
        Route::prefix('lyra')->group(function () {
            Route::get('status',                         [\App\Http\Controllers\Admin\AdminLyraController::class, 'status']);
            Route::post('status',                        [\App\Http\Controllers\Admin\AdminLyraController::class, 'updateStatus']);
            Route::get('stats',                          [\App\Http\Controllers\Admin\AdminLyraController::class, 'stats']);
            Route::get('stats/hourly',                   [\App\Http\Controllers\Admin\AdminLyraController::class, 'hourlyStats']);
            Route::get('intents/stats',                  [\App\Http\Controllers\Admin\AdminLyraController::class, 'intentStats']);

            // ── Sesiones ──────────────────────────────────────────────────
            Route::get('sessions',                       [\App\Http\Controllers\Admin\AdminLyraController::class, 'sessions']);
            Route::get('sessions/export',                [\App\Http\Controllers\Admin\AdminLyraController::class, 'exportSessions']);
            Route::get('sessions/stats',                 [\App\Http\Controllers\Admin\AdminLyraController::class, 'sessionStats']);
            Route::get('sessions/{id}',                  [\App\Http\Controllers\Admin\AdminLyraController::class, 'sessionDetail']);
            Route::patch('sessions/{id}',                [\App\Http\Controllers\Admin\AdminLyraController::class, 'updateSession']);
            Route::delete('sessions/{id}',               [\App\Http\Controllers\Admin\AdminLyraController::class, 'deleteSession']);
            Route::post('sessions/{id}/block',           [\App\Http\Controllers\Admin\AdminLyraController::class, 'blockSession']);
            Route::get('sessions/{id}/replay',           [\App\Http\Controllers\Admin\AdminLyraController::class, 'replaySession']);

            Route::get('health',                         [\App\Http\Controllers\Admin\AdminLyraHealthController::class, 'check']);
            Route::get('health/incidents',               [\App\Http\Controllers\Admin\AdminLyraHealthController::class, 'incidents']);
            Route::post('services/{service}/restart',      [\App\Http\Controllers\Admin\AdminLyraHealthController::class, 'restart']);
            Route::get('alerts/pending',                 [\App\Http\Controllers\Admin\AdminLyraAlertController::class, 'pending']);
            Route::post('alerts/{id}/read',              [\App\Http\Controllers\Admin\AdminLyraAlertController::class, 'markAsRead']);
            Route::get('versions',                       [\App\Http\Controllers\Admin\AdminLyraVersionController::class, 'index']);
            Route::post('versions',                      [\App\Http\Controllers\Admin\AdminLyraVersionController::class, 'store']);
            Route::post('versions/{id}/rollback',        [\App\Http\Controllers\Admin\AdminLyraVersionController::class, 'rollback']);
            Route::get('config',                         [\App\Http\Controllers\Admin\AdminLyraConfigController::class, 'show']);
            Route::put('config',                         [\App\Http\Controllers\Admin\AdminLyraConfigController::class, 'update']);
            Route::post('clear-cache',                   [\App\Http\Controllers\Admin\AdminLyraController::class, 'clearCache']);
        });
    });
});
