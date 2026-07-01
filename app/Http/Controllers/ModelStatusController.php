<?php

namespace App\Http\Controllers;

use App\Services\ThreeDModelService;
use Illuminate\Http\JsonResponse;

class ModelStatusController extends Controller
{
    public function __construct(private ThreeDModelService $service) {}

    /**
     * Estado del modelo 3D de una propiedad (polling endpoint).
     * GET /api/properties/{propertyId}/3d-status
     */
    public function show(int $propertyId): JsonResponse
    {
        $status = $this->service->getStatus($propertyId);

        if (!$status) {
            return response()->json([
                'success' => true,
                'data'    => ['status' => 'none'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $status,
        ]);
    }
}
