<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanoramaHotspot extends Model
{
    protected $fillable = ['panorama_id', 'target_panorama_id', 'yaw', 'pitch', 'label'];

    public function panorama()
    {
        return $this->belongsTo(Panorama::class);
    }

    public function target()
    {
        return $this->belongsTo(Panorama::class, 'target_panorama_id');
    }
}
