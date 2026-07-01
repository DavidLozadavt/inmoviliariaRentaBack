<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Neighborhood extends Model
{
    protected $fillable = ['name', 'city', 'slug', 'lat', 'lng'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];
}
