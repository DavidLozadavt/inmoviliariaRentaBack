<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Panorama extends Model
{
    protected $fillable = ['space', 'kind', 'projection', 'surface', 'path', 'thumb_path', 'width', 'size_kb', 'is_start', 'order'];

    protected $casts = ['is_start' => 'boolean'];

    public function owner()
    {
        return $this->morphTo();
    }

    public function hotspots()
    {
        return $this->hasMany(PanoramaHotspot::class);
    }
}
