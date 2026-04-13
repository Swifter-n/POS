<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Promo extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'code',
        'description',
        'discount_amount',
        'activated_at',
        'expired_at',
    ];

        protected static function booted()
    {
    static::creating(function ($model) {
        if (Auth::check() && Auth::user()->business_id) {
            $model->business_id = Auth::user()->business_id;
        }
    });
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
