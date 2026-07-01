<?php

namespace App\Models;

use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Report extends Model
{
    use HasFactory, HasSmartScopes;

    public function user(){return $this->belongsTo(User::class);}

    // Campos que se pueden asignar masivamente (por create, update, etc.)
    protected $fillable = [
        'type',
        'description',
        'status',
        'property_id',
        'reported_user_id',
        'user_id'
    ];

    public function reported_user() {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function property() {
        return $this->belongsTo(Property::class);
    }
}
