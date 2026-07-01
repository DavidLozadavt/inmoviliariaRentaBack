<?php

namespace App\Models;

use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasSmartScopes, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'id_documento',
        'status',
        'verification_status',
        'email_verified_at',
        'registration_date',
        'photo',
        'bio',
        'role',
        'department',
        'city',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ==================== RELACIONES ====================

    public function properties()
    {
        return $this->hasMany(Property::class);
    }

    public function contractsAsLandlord()
    {
        return $this->hasMany(Contract::class, 'landlord_id');
    }

    public function contractsAsTenant()
    {
        return $this->hasMany(Contract::class, 'tenant_id');
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    public function maintenances()
    {
        return $this->hasMany(Maintenance::class);
    }

    public function rentalRequests()
    {
        return $this->hasMany(RentalRequest::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function lyraSessions()
    {
        return $this->hasMany(LyraSession::class);
    }

    // ==================== JWT ====================

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
