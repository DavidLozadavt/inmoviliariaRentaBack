<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CaptureSession extends Model
{
    protected $fillable = [
        'capture_token', 'user_id', 'property_id',
        'source', 'status', 'video_s3_url', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['id', 'user_id'];

    // ==================== RELACIONES ====================

    public function user(): BelongsTo  { return $this->belongsTo(User::class); }
    public function property(): BelongsTo { return $this->belongsTo(Property::class); }

    // ==================== HELPERS ====================

    public function isExpired(): bool { return $this->expires_at->isPast(); }
    public function isWaiting(): bool { return $this->status === 'waiting'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }

    /**
     * Genera una nueva sesión de captura con token UUID y expiración de 30 minutos.
     */
    public static function generate(int $userId, ?int $propertyId = null, string $source = 'qr_mobile'): static
    {
        return static::create([
            'capture_token' => Str::uuid()->toString(),
            'user_id'       => $userId,
            'property_id'   => $propertyId,
            'source'        => $source,
            'status'        => 'waiting',
            'expires_at'    => now()->addMinutes(30),
        ]);
    }

    /**
     * Scope: solo sesiones activas (no expiradas, en espera).
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'waiting')->where('expires_at', '>', now());
    }
}
