<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class VerificationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'code',
        'token',
        'type',
        'expires_at',
        'used',
        'last_sent_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'used' => 'boolean',
    ];

    /**
     * Verificar si el código ha expirado
     */
    public function isExpired(): bool
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }

    /**
     * Verificar si el código ya fue usado
     */
    public function isUsed(): bool
    {
        return $this->used;
    }

    /**
     * Verificar si el código es válido
     */
    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }

    /**
     * Marcar el código como usado
     */
    public function markAsUsed(): void
    {
        $this->update(['used' => true]);
    }

    /**
     * Obtener códigos expirados o usados (para limpieza)
     */
    public static function scopeExpiredOrUsed($query)
    {
        return $query->where(function ($q) {
            $q->where('expires_at', '<', Carbon::now())
              ->orWhere('used', true);
        });
    }

    /**
     * Verificar cooldown antes de reenviar
     */
    public function canResend(int $cooldownSeconds = 120): bool
    {
        if (!$this->last_sent_at) {
            return true;
        }

        return Carbon::now()->greaterThan(
            $this->last_sent_at->addSeconds($cooldownSeconds)
        );
    }

    /**
     * Obtener tiempo restante de cooldown en segundos
     */
    public function cooldownRemaining(int $cooldownSeconds = 120): int
    {
        if (!$this->last_sent_at) {
            return 0;
        }

        $nextAllowed = $this->last_sent_at->addSeconds($cooldownSeconds);
        $now = Carbon::now();

        if ($now->greaterThan($nextAllowed)) {
            return 0;
        }

        return $now->diffInSeconds($nextAllowed);
    }
}
