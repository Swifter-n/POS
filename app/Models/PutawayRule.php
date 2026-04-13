<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class PutawayRule extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected static function booted()
    {
        static::saving(function ($model) {
            if (Auth::check() && !$model->business_id) {
                $model->business_id = Auth::user()->business_id;
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function targetZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'target_zone_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
