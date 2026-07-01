<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        $methods = Auth::user()->paymentMethods()->orderBy('is_default', 'desc')->get();

        return response()->json([
            'success' => true,
            'data'    => $methods,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'holder_name' => 'required|string',
            'last_four'   => 'required|string|size:4',
            'expiry_date' => 'required|string|regex:/^\d{2}\/\d{2}$/',
            'is_default'  => 'nullable|boolean',
        ]);

        $user = Auth::user();

        // If this is the first method, make it default
        if ($user->paymentMethods()->count() === 0) {
            $validated['is_default'] = true;
        } elseif (!empty($validated['is_default'])) {
            // If new is default, remove other defaults
            $user->paymentMethods()->update(['is_default' => false]);
        }

        $method = $user->paymentMethods()->create([
            'type'        => 'card',
            'holder_name' => $validated['holder_name'],
            'last_four'   => $validated['last_four'],
            'expiry_date' => $validated['expiry_date'],
            'is_default'  => $validated['is_default'] ?? false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tarjeta guardada exitosamente',
            'data'    => $method,
        ], 201);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        if ($paymentMethod->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
        }

        $paymentMethod->delete();

        return response()->json([
            'success' => true,
            'message' => 'Método de pago eliminado',
        ]);
    }
}
