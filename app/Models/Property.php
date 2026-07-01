<?php

namespace App\Models;

use App\Traits\HasSmartScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class Property extends Model
{
    use HasFactory, HasSmartScopes, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'address',
        'city',
        'type',
        'status',
        'approval_status',
        'visibility',
        'monthly_price',
        'area_m2',
        'num_bedrooms',
        'num_bathrooms',
        'included_services',
        'publication_date',
        'image_url',
        'user_id',
        'views',
        'lat',
        'lng',
        'accuracy',
    ];

    protected $casts = [
        'included_services' => 'array',
        'image_url'         => 'array', // Will keep this for now but move to images relation
        'publication_date'  => 'date',
        'monthly_price'     => 'decimal:2',
        'area_m2'           => 'decimal:2',
        'lat'               => 'decimal:7',
        'lng'               => 'decimal:7',
        'accuracy'          => 'decimal:2',
        'views'             => 'integer',
    ];

    protected $attributes = [
        'status'          => 'available',
        'approval_status' => 'pending',
        'visibility'      => 'hidden',
        'views'           => 0,
    ];

    // ==================== RELACIONES ====================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function maintenances()
    {
        return $this->hasMany(Maintenance::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function rentalRequests()
    {
        return $this->hasMany(RentalRequest::class);
    }

    public function images()
    {
        return $this->hasMany(PropertyImage::class)->orderBy('order');
    }

    public function threeDModel()
    {
        return $this->hasOne(Property3dModel::class);
    }

    public function captureSessions()
    {
        return $this->hasMany(CaptureSession::class);
    }

    // ==================== SCOPES ====================

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('title', 'LIKE', "%{$search}%")
                ->orWhere('description', 'LIKE', "%{$search}%")
                ->orWhere('city', 'LIKE', "%{$search}%")
                ->orWhere('address', 'LIKE', "%{$search}%");
        });
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status)) return $query;
        return $query->where('status', $status);
    }

    public function scopeApprovalStatus(Builder $query, ?string $approvalStatus): Builder
    {
        if (empty($approvalStatus)) return $query;
        return $query->where('approval_status', $approvalStatus);
    }

    public function scopeVisibility(Builder $query, ?string $visibility): Builder
    {
        if (empty($visibility)) return $query;
        return $query->where('visibility', $visibility);
    }

    public function scopeCity(Builder $query, ?string $city): Builder
    {
        if (empty($city)) return $query;
        return $query->where('city', 'LIKE', "%{$city}%");
    }

    public function scopeMinPrice(Builder $query, ?float $minPrice): Builder
    {
        if ($minPrice === null) return $query;
        return $query->where('monthly_price', '>=', $minPrice);
    }

    public function scopeMaxPrice(Builder $query, ?float $maxPrice): Builder
    {
        if ($maxPrice === null) return $query;
        return $query->where('monthly_price', '<=', $maxPrice);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('approval_status', 'approved')
            ->where('visibility', 'published')
            ->where('status', 'available');
    }

    public function scopeStatsByCity(Builder $query): Builder
    {
        return $query->select('city', \DB::raw('count(*) as count'))
            ->active()
            ->groupBy('city')
            ->orderByDesc('count');
    }

    public function scopeIncluded(Builder $query): Builder
    {
        return $query->with(['user:id,name,email,phone,photo']);
    }

    // ==================== MÉTODOS AUXILIARES ====================

    public function incrementViews(): void
    {
        $this->increment('views');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available'
            && $this->approval_status === 'approved'
            && $this->visibility === 'published';
    }

    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    public function isPublished(): bool
    {
        return $this->visibility === 'published';
    }

    public function getMainImageAttribute(): ?string
    {
        // Priorizar la primera imagen de la nueva relación
        $firstImage = $this->images->first();
        if ($firstImage) {
            return $firstImage->url;
        }

        // Fallback a image_url antiguo (que eran base64 o URLs)
        $images = $this->image_url;
        return is_array($images) && count($images) > 0 ? $images[0] : null;
    }
}
