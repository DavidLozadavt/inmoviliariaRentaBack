<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LyraAdminAlert extends Model
{
    protected $fillable = [
        'type',
        'message',
        'payload',
        'read_at'
    ];

    protected $casts = [
        'payload' => 'array',
        'read_at' => 'datetime'
    ];

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
