<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $query = Report::with(['user:id,name,email'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 15);
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'             => 'required|string|max:100',
            'description'      => 'required|string|max:2000',
            'property_id'      => 'nullable|integer|exists:properties,id',
            'reported_user_id' => 'nullable|integer|exists:users,id',
        ]);

        $validated['user_id'] = Auth::id(); // Guardamos el autor
        $validated['status']  = 'pending';

        $report = Report::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Reporte creado exitosamente',
            'data'    => $report,
        ], 201);
    }

    public function show(Report $report): JsonResponse
    {
        if ($report->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para ver este reporte',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $report->load('user:id,name'),
        ]);
    }

    public function update(Request $request, Report $report): JsonResponse
    {
        if ($report->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para editar este reporte',
            ], 403);
        }

        $validated = $request->validate([
            'type'           => 'sometimes|string|max:100',
            'applied_filter' => 'sometimes|nullable|string|max:255',
        ]);

        $report->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Reporte actualizado exitosamente',
            'data'    => $report->fresh(),
        ]);
    }

    public function destroy(Report $report): JsonResponse
    {
        if ($report->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar este reporte',
            ], 403);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Reporte eliminado exitosamente',
        ]);
    }
}
