<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\User;

class ActivityService
{
    /**
     * Guardar una actividad en la base de datos y notificar a los admins.
     */
    public static function log(string $type, array $data, string $icon, string $color, string $title, $timestamp = null): ActivityLog
    {
        $activity = ActivityLog::create([
            'type'       => $type,
            'title'      => $title,
            'icon'       => $icon,
            'color'      => $color,
            'data'       => $data,
            'created_at' => $timestamp ?? now(),
            'updated_at' => $timestamp ?? now(),
        ]);

        // Crear notificación descriptiva para todos los administradores/soporte
        try {
            $message = self::buildNotificationMessage($type, $data);
            $admins = User::whereIn('role', ['admin', 'support'])->get();
            foreach ($admins as $admin) {
                Notification::create([
                    'user_id' => $admin->id,
                    'title'   => $title,
                    'message' => $message,
                    'type'    => 'system',
                    'read'    => false,
                    'data'    => array_merge($data, ['activity_id' => $activity->id, 'activity_type' => $type])
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo crear notificación para actividad', [
                'activity_id' => $activity->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $activity;
    }

    /**
     * Generar un mensaje descriptivo basado en el tipo de actividad.
     */
    private static function buildNotificationMessage(string $type, array $data): string
    {
        return match ($type) {
            'user_registered'        => ($data['user_name'] ?? 'Un usuario') . ' se registró en la plataforma.',
            'user_deleted'           => ($data['deleted_by'] ?? 'Sistema') . ' eliminó al usuario ' . ($data['user_name'] ?? ''),
            'user_status_changed'    => 'El estado de ' . ($data['user_name'] ?? 'un usuario') . ' cambió de ' . ($data['old_status'] ?? '?') . ' a ' . ($data['new_status'] ?? '?'),
            'user_role_changed'      => ($data['changed_by'] ?? 'Sistema') . ' cambió el rol de ' . ($data['user_name'] ?? 'un usuario') . ' a ' . ($data['new_role'] ?? '?'),
            'user_updated'           => ($data['updated_by'] ?? 'Sistema') . ' actualizó los datos de ' . ($data['user_name'] ?? 'un usuario'),
            'property_created'       => ($data['created_by'] ?? 'Un usuario') . ' publicó la propiedad "' . ($data['property_title'] ?? '') . '"',
            'property_deleted'       => ($data['deleted_by'] ?? 'Sistema') . ' eliminó la propiedad "' . ($data['property_title'] ?? '') . '"',
            'property_updated'       => ($data['updated_by'] ?? 'Sistema') . ' actualizó la propiedad "' . ($data['property_title'] ?? '') . '"',
            'property_status_changed'=> 'El estado de "' . ($data['property_title'] ?? 'una propiedad') . '" cambió a ' . ($data['new_status'] ?? '?'),
            'contract_created'       => ($data['created_by'] ?? 'Un usuario') . ' creó un contrato para "' . ($data['property_title'] ?? 'una propiedad') . '"',
            'payment_received'       => ($data['received_by'] ?? 'Un usuario') . ' realizó un pago de $' . number_format($data['amount'] ?? 0, 0, ',', '.'),
            'maintenance_requested'  => ($data['requested_by'] ?? 'Un usuario') . ' solicitó mantenimiento para "' . ($data['property_title'] ?? 'una propiedad') . '"',
            'rental_request'         => ($data['requested_by'] ?? 'Un usuario') . ' solicitó arriendo para "' . ($data['property_title'] ?? 'una propiedad') . '"',
            default                  => 'Se ha registrado una nueva actividad: ' . $type,
        };
    }

    /**
     * Obtener actividades recientes desde la BD.
     */
    public static function getRecentActivities(int $limit = 10): array
    {
        return ActivityLog::orderBy('created_at', 'desc')
            ->take($limit)
            ->get()
            ->map(fn(ActivityLog $log) => [
                'id'         => 'activity_' . $log->id,
                'type'       => $log->type,
                'data'       => $log->data ?? [],
                'created_at' => $log->created_at->toISOString(),
                'timestamp'  => $log->created_at->timestamp,
                'icon'       => $log->icon,
                'color'      => $log->color,
                'title'      => $log->title,
            ])
            ->toArray();
    }

    // ==================== USUARIOS ====================

    public static function logUserDeleted($user, $deletedBy = null): ActivityLog
    {
        return self::log('user_deleted', [
            'user_name'     => $user->name,
            'user_email'    => $user->email,
            'user_id'       => $user->id,
            'deleted_by'    => $deletedBy?->name ?? 'Sistema',
            'deleted_by_id' => $deletedBy?->id,
        ], 'trash', '#ef4444', 'Usuario eliminado');
    }

    public static function logUserStatusChanged($user, $oldStatus, $changedBy = null): ActivityLog
    {
        return self::log('user_status_changed', [
            'user_name'     => $user->name,
            'user_id'       => $user->id,
            'old_status'    => $oldStatus,
            'new_status'    => $user->status,
            'changed_by'    => $changedBy?->name ?? 'Sistema',
            'changed_by_id' => $changedBy?->id,
        ], 'refresh-cw', '#06b6d4', 'Estado de usuario cambiado');
    }

    public static function logUserRoleChanged($user, $oldRole, $changedBy = null): ActivityLog
    {
        return self::log('user_role_changed', [
            'user_name'     => $user->name,
            'user_id'       => $user->id,
            'old_role'      => $oldRole,
            'new_role'      => $user->role,
            'changed_by'    => $changedBy?->name ?? 'Sistema',
            'changed_by_id' => $changedBy?->id,
        ], 'shield', '#8b5cf6', 'Rol de usuario cambiado');
    }

    public static function logUserCreatedByAdmin($user, $createdBy = null): ActivityLog
    {
        return self::log('user_registered', [
            'user_name'     => $user->name,
            'user_email'    => $user->email,
            'user_id'       => $user->id,
            'created_by'    => $createdBy?->name ?? 'Sistema',
            'created_by_id' => $createdBy?->id,
        ], 'user-plus', '#3b86f7', 'Nuevo usuario registrado', $user->created_at);
    }

    public static function logUserUpdated($user, $changes, $updatedBy = null): ActivityLog
    {
        return self::log('user_updated', [
            'user_name'     => $user->name,
            'user_id'       => $user->id,
            'changes'       => $changes,
            'updated_by'    => $updatedBy?->name ?? 'Sistema',
            'updated_by_id' => $updatedBy?->id,
        ], 'edit', '#f59e0b', 'Usuario actualizado');
    }

    // ==================== PROPIEDADES ====================

    public static function logPropertyCreated($property, $createdBy = null): ActivityLog
    {
        return self::log('property_created', [
            'property_title' => $property->title,
            'property_id'    => $property->id,
            'created_by'     => $createdBy?->name ?? 'Sistema',
            'created_by_id'  => $createdBy?->id,
        ], 'home', '#10b981', 'Nueva propiedad creada', $property->created_at);
    }

    public static function logPropertyDeleted($property, $deletedBy = null): ActivityLog
    {
        return self::log('property_deleted', [
            'property_title' => $property->title,
            'property_id'    => $property->id,
            'deleted_by'     => $deletedBy?->name ?? 'Sistema',
            'deleted_by_id'  => $deletedBy?->id,
        ], 'trash', '#ef4444', 'Propiedad eliminada');
    }

    public static function logPropertyUpdated($property, $changes, $updatedBy = null): ActivityLog
    {
        return self::log('property_updated', [
            'property_title' => $property->title,
            'property_id'    => $property->id,
            'changes'        => $changes,
            'updated_by'     => $updatedBy?->name ?? 'Sistema',
            'updated_by_id'  => $updatedBy?->id,
        ], 'edit', '#f59e0b', 'Propiedad actualizada');
    }

    public static function logPropertyStatusChanged($property, $oldStatus, $changedBy = null): ActivityLog
    {
        return self::log('property_status_changed', [
            'property_title' => $property->title,
            'property_id'    => $property->id,
            'old_status'     => $oldStatus,
            'new_status'     => $property->status,
            'changed_by'     => $changedBy?->name ?? 'Sistema',
            'changed_by_id'  => $changedBy?->id,
        ], 'refresh-cw', '#06b6d4', 'Estado de propiedad cambiado');
    }

    // ==================== CONTRATOS ====================

    public static function logContractCreated($contract, $createdBy = null): ActivityLog
    {
        return self::log('contract_created', [
            'contract_id'    => $contract->id,
            'property_title' => $contract->property->title ?? 'Propiedad',
            'created_by'     => $createdBy?->name ?? 'Sistema',
            'created_by_id'  => $createdBy?->id,
        ], 'file-contract', '#f59e0b', 'Contrato creado', $contract->created_at);
    }

    // ==================== PAGOS ====================

    public static function logPaymentReceived($payment, $receivedBy = null): ActivityLog
    {
        return self::log('payment_received', [
            'amount'         => $payment->amount,
            'payment_id'     => $payment->id,
            'received_by'    => $receivedBy?->name ?? 'Sistema',
            'received_by_id' => $receivedBy?->id,
        ], 'dollar-sign', '#8b5cf6', 'Pago recibido', $payment->created_at);
    }

    // ==================== MANTENIMIENTOS ====================

    public static function logMaintenanceRequested($maintenance, $requestedBy = null): ActivityLog
    {
        return self::log('maintenance_requested', [
            'maintenance_id'   => $maintenance->id,
            'property_title'   => $maintenance->property->title ?? 'Propiedad',
            'requested_by'     => $requestedBy?->name ?? 'Sistema',
            'requested_by_id'  => $requestedBy?->id,
        ], 'wrench', '#ef4444', 'Mantenimiento solicitado', $maintenance->created_at);
    }

    // ==================== SOLICITUDES ====================

    public static function logRentalRequest($request, $requestedBy = null): ActivityLog
    {
        return self::log('rental_request', [
            'request_id'       => $request->id,
            'property_title'   => $request->property->title ?? 'Propiedad',
            'requested_by'     => $requestedBy?->name ?? 'Sistema',
            'requested_by_id'  => $requestedBy?->id,
        ], 'clipboard', '#06b6d4', 'Solicitud de arriendo', $request->created_at);
    }
}
