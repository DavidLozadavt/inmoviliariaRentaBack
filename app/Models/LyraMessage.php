<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LyraMessage extends Model
{
    public $timestamps = false; // Using created_at only (timestamp column is not managed by eloquent automatically)

    protected $fillable = [
        'lyra_session_id',
        'role',
        'content',
        'intent',
        'confidence',
        'entities',
        'thinking_process',
        'processing_time_ms',
        'properties_found',
        'created_at',
    ];

    protected $casts = [
        'entities'         => 'array',
        'thinking_process' => 'array',
        'created_at'       => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LyraSession::class, 'lyra_session_id');
    }
}
