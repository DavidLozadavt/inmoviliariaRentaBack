<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LyraServiceIncident extends Model
{
    protected $fillable = [
        'service',
        'status',
        'latency_ms',
        'detail',
        'resolved_at'
    ];

    protected $casts = [
        'resolved_at' => 'datetime'
    ];

    public function scopeActive($query)
    {
        return $query->whereNull('resolved_at');
    }
}
