<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class LyraSession extends Model
{
    protected $fillable = ['session_id', 'user_id', 'city', 'last_intent', 'status', 'metadata'];
    protected $casts = [
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(LyraMessage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopePorIntent(Builder $query, string $intent): Builder
    {
        return $query->where('last_intent', $intent);
    }

    public function scopePorCiudad(Builder $query, string $city): Builder
    {
        return $query->where('city', $city);
    }

    public function scopePorFecha(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('updated_at', [$from, $to]);
    }

    public function scopeAnonimas(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }
}
