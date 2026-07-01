<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RentalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'user_id',
        'owner_id',
        'requested_date',
        'requested_time',
        'counter_date',
        'counter_time',
        'status',
        'visit_end_time',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'visit_end_time' => 'datetime:Y-m-d H:i:s',
        'counter_date' => 'date',
    ];

    // Relación con la propiedad
    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    // Relación con el inquilino (user)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Relación con el dueño (owner)
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // Scopes útiles
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOwner($query, $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }
}