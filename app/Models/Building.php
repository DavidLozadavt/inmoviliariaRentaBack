<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Building extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['user_id', 'name', 'address', 'city', 'config'];

    protected $hidden = ['public_token', 'cover_path', 'preview_path', 'facade_front_path', 'facade_side_path',
        'facade_left_path', 'facade_back_path', 'roof_path', 'site_plan_path'];

    protected $appends = ['cover_url', 'preview_url', 'facade_front_url', 'facade_side_url', 'facade_left_url',
        'facade_back_url', 'roof_url', 'site_plan_url'];

    private function publicUrl(?string $path): ?string
    {
        return $path ? \Illuminate\Support\Facades\Storage::disk('public')->url($path) : null;
    }

    public function getFacadeLeftUrlAttribute(): ?string
    {
        return $this->publicUrl($this->facade_left_path);
    }

    public function getFacadeBackUrlAttribute(): ?string
    {
        return $this->publicUrl($this->facade_back_path);
    }

    public function getRoofUrlAttribute(): ?string
    {
        return $this->publicUrl($this->roof_path);
    }

    protected $casts = [
        'config'            => 'array',
        'config_version'    => 'integer',
        'public_expires_at' => 'datetime',
    ];

    /** Foto del edificio que envió el cliente. */
    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->cover_path) : null;
    }

    /** Imagen del modelo 3D (la toma el editor al guardar). */
    public function getPreviewUrlAttribute(): ?string
    {
        return $this->preview_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->preview_path) : null;
    }

    /** Texturas de fachada (frente y costado) que usa el modelo 3D. */
    public function getFacadeFrontUrlAttribute(): ?string
    {
        return $this->facade_front_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->facade_front_path) : null;
    }

    public function getFacadeSideUrlAttribute(): ?string
    {
        return $this->facade_side_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->facade_side_path) : null;
    }

    /** Plano / vista aérea del conjunto (suelo del modelo 3D). */
    public function getSitePlanUrlAttribute(): ?string
    {
        return $this->site_plan_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->site_plan_path) : null;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function unitTypes()
    {
        return $this->hasMany(UnitType::class)->orderBy('code');
    }

    public function units()
    {
        return $this->hasMany(Property::class);
    }

    /** Edificios visibles para el usuario (admin/support ven todo). */
    public function scopeVisibleTo($query, User $user)
    {
        if (in_array($user->role, ['admin', 'support'], true)) {
            return $query;
        }
        return $query->where('user_id', $user->id);
    }
}
