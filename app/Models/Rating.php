<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_role',
        'score',
        'comment',
        'date',
        'contract_id',
        'user_id',
    ];

    protected $casts = [
        'score' => 'integer',
        'date'  => 'date',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
