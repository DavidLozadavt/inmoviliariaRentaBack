<?php

namespace App\Models;

use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Maintenance extends Model
{
    use HasFactory, HasSmartScopes;

    protected $fillable = [
        'title',
        'description',
        'request_date',
        'status',
        'priority',
        'resolution_date',
        'validated_by_tenant',
        'property_id',
        'user_id',
    ];

    protected $casts = [
        'request_date'    => 'date',
        'resolution_date' => 'date',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
