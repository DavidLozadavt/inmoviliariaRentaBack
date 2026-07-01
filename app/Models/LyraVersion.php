<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LyraVersion extends Model
{
    use SoftDeletes;

    protected $fillable = ['version', 'changelog', 'metrics', 'deployed_by', 'is_current', 'deployed_at'];
    protected $casts = [
        'metrics'     => 'array',
        'is_current'  => 'boolean',
        'deployed_at' => 'datetime',
    ];

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function activar(): void
    {
        DB::transaction(function () {
            static::query()->update(['is_current' => false]);
            $this->update(['is_current' => true]);
        });
    }
}
