<?php

namespace App\Http\Controllers;

use App\Models\Rating;
use App\Models\Contract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $query = Rating::with(['contract.property:id,title', 'user:id,name,photo'])
            ->where(function ($q) use ($userId) {
                $q->where('user_id', $userId)
                  ->orWhereHas('contract', function ($sub) use ($userId) {
                      $sub->where('tenant_id', $userId)->orWhere('landlord_id', $userId);
                  });
            })
            ->orderBy('created_at', 'desc');

        $perPage = (int) $request->input('per_page', 15);
        $ratings = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $ratings->items(),
            'meta'    => [
                'current_page' => $ratings->currentPage(),
                'last_page'    => $ratings->lastPage(),
                'per_page'     => $ratings->perPage(),
                'total'        => $ratings->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contract_id'    => 'required|integer|exists:contracts,id',
            'recipient_role' => 'required|string|in:tenant,landlord',
            'score'          => 'required|integer|min:1|max:5',
            'comment'        => 'nullable|string|max:1000',
        ]);

        $userId   = Auth::id();
        $contract = Contract::findOrFail($validated['contract_id']);

        // Verificar que es parte del contrato
        if ($contract->tenant_id !== $userId && $contract->landlord_id !== $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para calificar en este contrato',
            ], 403);
        }

        // Evitar calificación duplicada
        $exists = Rating::where('contract_id', $contract->id)
            ->where('user_id', $userId)
            ->where('recipient_role', $validated['recipient_role'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Ya has calificado a esta persona en este contrato',
            ], 400);
        }

        $validated['user_id'] = $userId;
        $validated['date']    = now()->toDateString();

        $rating = Rating::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Calificación registrada exitosamente',
            'data'    => $rating->load(['contract.property:id,title', 'user:id,name']),
        ], 201);
    }

    public function show(Rating $rating): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $rating->load(['contract.property:id,title', 'user:id,name,photo']),
        ]);
    }

    public function update(Request $request, Rating $rating): JsonResponse
    {
        if ($rating->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para editar esta calificación',
            ], 403);
        }

        $validated = $request->validate([
            'score'   => 'sometimes|integer|min:1|max:5',
            'comment' => 'sometimes|nullable|string|max:1000',
        ]);

        $rating->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Calificación actualizada exitosamente',
            'data'    => $rating->fresh()->load(['contract.property:id,title', 'user:id,name']),
        ]);
    }

    public function destroy(Rating $rating): JsonResponse
    {
        if ($rating->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar esta calificación',
            ], 403);
        }

        $rating->delete();

        return response()->json([
            'success' => true,
            'message' => 'Calificación eliminada exitosamente',
        ]);
    }
}
