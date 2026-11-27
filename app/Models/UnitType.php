<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitType extends Model
{
    protected $fillable = ['building_id', 'code', 'name', 'area_m2', 'bedrooms', 'bathrooms', 'color'];

    protected $casts = [
        'area_m2'   => 'decimal:2',
        'bedrooms'  => 'integer',
        'bathrooms' => 'integer',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function panoramas()
    {
        return $this->morphMany(Panorama::class, 'owner')->orderBy('order');
    }
}
