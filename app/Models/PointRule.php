<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id', 'name', 'type', 'value', 'conditions',
        'start_date', 'end_date', 'is_active'
    ];

    protected $casts = [
        'conditions' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
    ];
}
