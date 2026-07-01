<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RentalRequest;
use App\Models\Property;
use App\Models\Contract;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RentalRequestController extends Controller
{
    // ==================== INQUILINO ====================

    /**
     * Crear solicitud de visita
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'requested_date' => 'required|date',
            'requested_time' => 'required|date_format:H:i',
        ]);

        $property = Property::findOrFail($validated['property_id']);
        $userId = Auth::id();

        // Verificar que la propiedad está disponible
        if ($property->status !== 'available') {
            return response()->json([
                'message' => 'Esta propiedad no está disponible'
            ], 400);
        }

        // Verificar que no haya una solicitud activa para esta propiedad
        $existingRequest = RentalRequest::where('property_id', $property->id)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'accepted', 'counter_proposed'])
            ->first();

        if ($existingRequest) {
            return response()->json([
                'message' => 'Ya tienes una solicitud activa para esta propiedad'
            ], 400);
        }

        // Crear la solicitud
        $rentalRequest = RentalRequest::create([
            'property_id' => $property->id,
            'user_id' => $userId,
            'owner_id' => $property->user_id,
            'requested_date' => $validated['requested_date'],
            'requested_time' => $validated['requested_time'],
            'status' => 'pending',
        ]);

        // Crear notificación para el dueño
        Notification::create([
            'user_id' => $property->user_id,
            'type' => 'rental_request',
            'title' => 'Nueva solicitud de visita',
            'message' => '<strong>' . Auth::user()->name . '</strong> quiere visitar tu propiedad <strong>' . $property->title . '</strong>',
            'data' => json_encode([
                'rental_request_id' => $rentalRequest->id,
                'property_id' => $property->id,
            ]),
        ]);

        return response()->json([
            'message' => 'Solicitud enviada exitosamente',
            'data' => $rentalRequest->load(['property', 'user', 'owner'])
        ], 201);
    }

    /**
     * Obtener mis solicitudes (inquilino)
     */
    public function getMyRequests()
    {
        $requests = RentalRequest::where('user_id', Auth::id())
            ->with(['property', 'owner'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    /**
     * Aceptar contra-propuesta del dueño
     */
    public function acceptCounterProposal($id)
    {
        $request = RentalRequest::with(['property', 'owner'])->findOrFail($id);

        if ($request->user_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($request->status !== 'counter_proposed') {
            return response()->json(['message' => 'No hay contra-propuesta pendiente'], 400);
        }

        // Calcular visit_end_time con la nueva fecha propuesta
        $visitEndTime = $this->calculateVisitEndTime($request->counter_date, $request->counter_time);

        $request->update([
            'requested_date' => $request->counter_date,
            'requested_time' => $request->counter_time,
            'counter_date' => null,
            'counter_time' => null,
            'status' => 'accepted',
            'visit_end_time' => $visitEndTime,
        ]);

        // Notificar al dueño
        Notification::create([
            'user_id' => $request->owner_id,
            'type' => 'counter_accepted',
            'title' => 'Contra-propuesta aceptada',
            'message' => '<strong>' . Auth::user()->name . '</strong> aceptó tu propuesta de fecha para visitar <strong>' . $request->property->title . '</strong>',
            'data' => json_encode([
                'rental_request_id' => $request->id,
                'property_id' => $request->property_id,
            ]),
        ]);

        return response()->json([
            'message' => 'Contra-propuesta aceptada',
            'data' => $request->fresh()->load(['property', 'owner'])
        ]);
    }

    /**
     * Rechazar contra-propuesta
     */
    public function rejectCounterProposal($id)
    {
        $request = RentalRequest::with('property')->findOrFail($id);

        if ($request->user_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->update([
            'status' => 'rejected',
            'counter_date' => null,
            'counter_time' => null,
        ]);

        // Notificar al dueño
        Notification::create([
            'user_id' => $request->owner_id,
            'type' => 'counter_rejected',
            'title' => 'Contra-propuesta rechazada',
            'message' => '<strong>' . Auth::user()->name . '</strong> rechazó tu propuesta de fecha',
            'data' => json_encode([
                'rental_request_id' => $request->id,
            ]),
        ]);

        return response()->json(['message' => 'Contra-propuesta rechazada']);
    }

    // ==================== DUEÑO ====================

    /**
     * Obtener solicitudes recibidas (dueño)
     */
    public function getOwnerRequests()
    {
        $requests = RentalRequest::where('owner_id', Auth::id())
            ->with(['property', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    /**
     * Aceptar solicitud
     */
    public function acceptRequest($id)
    {
        $request = RentalRequest::with(['property', 'user'])->findOrFail($id);

        // Verificar que es el dueño
        if ($request->owner_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Verificar que está en estado pendiente
        if ($request->status !== 'pending') {
            return response()->json(['message' => 'Esta solicitud ya fue procesada'], 400);
        }

        // Calcular visit_end_time
        $visitEndTime = $this->calculateVisitEndTime($request->requested_date, $request->requested_time);

        $request->update([
            'status' => 'accepted',
            'visit_end_time' => $visitEndTime,
        ]);

        // Notificar al inquilino
        Notification::create([
            'user_id' => $request->user_id,
            'type' => 'request_accepted',
            'title' => '¡Visita confirmada!',
            'message' => 'El dueño de <strong>' . $request->property->title . '</strong> aceptó tu solicitud de visita para el ' . Carbon::parse($request->requested_date)->format('d/m/Y') . ' a las ' . $request->requested_time,
            'data' => json_encode([
                'rental_request_id' => $request->id,
                'property_id' => $request->property_id,
                'visit_end_time' => $visitEndTime->toIso8601String(), // Agregar para debugging
            ]),
        ]);

        return response()->json([
            'message' => 'Solicitud aceptada',
            'data' => $request->fresh()->load(['property', 'user'])
        ]);
    }

    /**
     * Rechazar solicitud
     */
    public function rejectRequest($id)
    {
        $request = RentalRequest::with('property')->findOrFail($id);

        if ($request->owner_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->update(['status' => 'rejected']);

        // Notificar al inquilino
        Notification::create([
            'user_id' => $request->user_id,
            'type' => 'request_rejected',
            'title' => 'Solicitud rechazada',
            'message' => 'El dueño de <strong>' . $request->property->title . '</strong> rechazó tu solicitud de visita',
            'data' => json_encode([
                'rental_request_id' => $request->id,
            ]),
        ]);

        return response()->json(['message' => 'Solicitud rechazada']);
    }

    /**
     * Proponer otra fecha
     */
    public function counterPropose(Request $request, $id)
    {
        $validated = $request->validate([
            'counter_date' => 'required|date|after_or_equal:today',
            'counter_time' => 'required|date_format:H:i',
        ]);

        $rentalRequest = RentalRequest::with('property')->findOrFail($id);

        if ($rentalRequest->owner_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $rentalRequest->update([
            'counter_date' => $validated['counter_date'],
            'counter_time' => $validated['counter_time'],
            'status' => 'counter_proposed',
        ]);

        // Notificar al inquilino
        Notification::create([
            'user_id' => $rentalRequest->user_id,
            'type' => 'counter_proposal',
            'title' => 'Nueva fecha propuesta',
            'message' => 'El dueño de <strong>' . $rentalRequest->property->title . '</strong> propone visitarla el ' . Carbon::parse($validated['counter_date'])->format('d/m/Y') . ' a las ' . $validated['counter_time'],
            'data' => json_encode([
                'rental_request_id' => $rentalRequest->id,
                'property_id' => $rentalRequest->property_id,
            ]),
        ]);

        return response()->json([
            'message' => 'Contra-propuesta enviada',
            'data' => $rentalRequest->fresh()->load(['property', 'user'])
        ]);
    }

    /**
     * Verificar si la visita ya terminó (para habilitar botón de continuar)
     */
    public function checkVisitStatus($id)
    {
        $request = RentalRequest::findOrFail($id);

        if ($request->owner_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!$request->visit_end_time) {
            return response()->json([
                'can_continue' => false,
                'message' => 'No hay hora de finalización de visita programada'
            ]);
        }

        // Usar timezone de Colombia
        $visitEnd = Carbon::parse($request->visit_end_time, 'America/Bogota');
        $now = Carbon::now('America/Bogota');

        $canContinue = $now->gte($visitEnd);

        $response = [
            'can_continue' => $canContinue,
            'visit_end_time' => $visitEnd->toIso8601String(),
            'current_time' => $now->toIso8601String(),
            'visit_end_formatted' => $visitEnd->format('Y-m-d H:i:s'),
            'current_formatted' => $now->format('Y-m-d H:i:s'),
        ];

        if (!$canContinue) {
            $response['time_remaining'] = $visitEnd->diffForHumans($now, true);
            $response['minutes_remaining'] = $now->diffInMinutes($visitEnd);
        }

        return response()->json($response);
    }
    /**
     * Enviar términos del contrato
     */
    public function sendContractTerms(Request $request)
    {
        $validated = $request->validate([
            'rental_request_id' => 'required|exists:rental_requests,id',
            'property_id' => 'required|exists:properties,id',
            'tenant_id' => 'required|exists:users,id',
            'landlord_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'monthly_price' => 'required|numeric|min:0',
            'deposit' => 'required|numeric|min:0',
            'clauses' => 'required|array',
            'clauses.*' => 'string',
            'payment_day' => 'required|integer|min:1|max:31',
            'late_fee' => 'required|numeric|min:0',
            'utilities_included' => 'nullable|array',
            'utilities_included.*' => 'string',
            'special_conditions' => 'nullable|string',
        ]);

        $rentalRequest = RentalRequest::with('property')->findOrFail($validated['rental_request_id']);

        // Verificar que es el dueño
        if ($rentalRequest->owner_id !== Auth::id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Verificar que la solicitud está en estado aceptado
        if ($rentalRequest->status !== 'accepted') {
            return response()->json(['message' => 'La solicitud debe estar aceptada para enviar un contrato'], 400);
        }

        // Verificar que la visita ya terminó
        if ($rentalRequest->visit_end_time) {
            $visitEnd = Carbon::parse($rentalRequest->visit_end_time);
            if (Carbon::now()->lt($visitEnd)) {
                return response()->json([
                    'message' => 'Debes esperar a que termine la visita programada'
                ], 400);
            }
        }

        // Preparar términos del contrato
        $contractTerms = [
            'monthly_price' => $validated['monthly_price'],
            'deposit' => $validated['deposit'],
            'clauses' => $validated['clauses'],
            'payment_day' => $validated['payment_day'],
            'late_fee' => $validated['late_fee'],
            'utilities_included' => $validated['utilities_included'] ?? [],
            'special_conditions' => $validated['special_conditions'] ?? '',
        ];

        // Crear el contrato
        $contract = Contract::create([
            'property_id' => $validated['property_id'],
            'landlord_id' => $validated['landlord_id'],
            'tenant_id' => $validated['tenant_id'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'deposit' => $validated['deposit'],
            'status' => 'pending',
            'document_path' => $contractTerms, // El mutator lo convierte a JSON
            'validated_by_support' => 'no',
            'accepted_by_tenant' => 'no',
        ]);

        // Actualizar estado de la solicitud
        $rentalRequest->update(['status' => 'contract_sent']);

        // Notificar al inquilino
        Notification::create([
            'user_id' => $validated['tenant_id'],
            'type' => 'contract_sent',
            'title' => '📄 Contrato recibido',
            'message' => 'El dueño de <strong>' . $rentalRequest->property->title . '</strong> te envió un contrato de arrendamiento. Revísalo y acéptalo si estás de acuerdo.',
            'data' => json_encode([
                'contract_id' => $contract->id,
                'property_id' => $validated['property_id'],
                'rental_request_id' => $rentalRequest->id,
            ]),
        ]);

        return response()->json([
            'message' => 'Contrato enviado exitosamente',
            'data' => $contract->load(['property', 'tenant', 'landlord'])
        ], 201);
    }


    // ==================== GENERAL ====================

    /**
     * Obtener detalles de una solicitud
     */
    public function getDetails($id)
    {
        $request = RentalRequest::with(['property', 'user', 'owner'])
            ->findOrFail($id);

        // Verificar que el usuario tiene permiso
        $userId = Auth::id();
        if ($request->user_id !== $userId && $request->owner_id !== $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        return response()->json($request);
    }

    /**
     * Cancelar solicitud
     */
    public function cancel($id)
    {
        $request = RentalRequest::findOrFail($id);

        $userId = Auth::id();
        if ($request->user_id !== $userId && $request->owner_id !== $userId) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $request->delete();

        return response()->json(['message' => 'Solicitud cancelada']);
    }

    // ==================== HELPERS ====================

    /**
     * Calcular hora de fin de visita (1 hora después)
     */
    private function calculateVisitEndTime($date, $time)
    {
        $datetime = Carbon::parse($date);
        list($hour, $minute) = explode(':', $time);
        $datetime->setTime((int)$hour, (int)$minute, 0);
        return $datetime->addMinutes(1);
    }
}