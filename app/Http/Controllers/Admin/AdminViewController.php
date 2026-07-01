<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminViewController extends Controller
{
    /**
     * GET /api/admin/views/stats
     * Estadísticas de vistas de propiedades.
     */
    public function stats(): JsonResponse
    {
        $stats = Property::query()
            ->selectRaw("
                COALESCE(SUM(views), 0) as total_views,
                COALESCE(AVG(views), 0) as avg_views,
                COALESCE(MAX(views), 0) as max_views
            ")
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_views' => (int) $stats->total_views,
                'avg_views'   => round((float) $stats->avg_views, 1),
                'max_views'   => (int) $stats->max_views,
            ],
        ]);
    }

    /**
     * GET /api/admin/views/top-properties
     * Propiedades más vistas.
     */
    public function topProperties(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->input('limit', 10), 1), 50);

        $properties = Property::with('user:id,name,email')
            ->select('id', 'title', 'city', 'monthly_price', 'views', 'user_id', 'created_at')
            ->where('views', '>', 0)
            ->orderBy('views', 'desc')
            ->take($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $properties,
        ]);
    }

    /**
     * GET /api/admin/views/by-city
     * Vistas agrupadas por ciudad.
     */
    public function viewsByCity(): JsonResponse
    {
        $byCity = Property::selectRaw('city, SUM(views) as total_views, COUNT(*) as properties_count')
            ->groupBy('city')
            ->orderByDesc('total_views')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $byCity,
        ]);
    }
}
