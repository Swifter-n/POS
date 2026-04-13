<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

class ShipmentRouteArea extends Pivot
{
    protected $guarded = [];

    protected static function booted()
    {
        static::saving(function ($model) {
            if (Auth::check() && !$model->business_id) { // Cek jika belum ada
                $model->business_id = Auth::user()->business_id;
            }
        });
    }



}

