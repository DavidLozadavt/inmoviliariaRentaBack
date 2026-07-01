<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferencePlace extends Model
{
    protected $fillable = ['name', 'type', 'city', 'lat', 'lng'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];
}
