<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'path',
        'is_main',
        'order',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Accesor para obtener la URL completa de la imagen.
     */
    public function getUrlAttribute()
    {
        if (!$this->path) return null;
        
        // Si ya es una URL completa (legacy o external), retornarla tal cual
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        // Usar el disco por defecto (public o s3) para generar la URL correcta
        return \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'public'))->url($this->path);
    }

    /**
     * Compatibilidad con frontend que busca image_url
     */
    public function getImageUrlAttribute()
    {
        return $this->getUrlAttribute();
    }

    protected $appends = ['url', 'image_url'];
}
