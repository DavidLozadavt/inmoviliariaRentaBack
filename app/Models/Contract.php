<?php

namespace App\Models;

use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, HasSmartScopes, SoftDeletes;

    protected $fillable = [
        'property_id',
        'landlord_id',
        'tenant_id',
        'start_date',
        'end_date',
        'status',
        'document_path',
        'deposit',
        'validated_by_support',
        'support_validation_date',
        'accepted_by_tenant',
        'tenant_acceptance_date',
    ];

    protected $casts = [
        'start_date'              => 'date',
        'end_date'                => 'date',
        'support_validation_date' => 'datetime',
        'tenant_acceptance_date'  => 'datetime',
        'deposit'                 => 'decimal:2',
        'document_path'           => 'json',
    ];

    // ==================== RELACIONES ====================

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function landlord()
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function rentalRequest()
    {
        return $this->hasOne(RentalRequest::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
