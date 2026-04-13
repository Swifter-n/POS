<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Fleet extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected static function booted()
    {
        // Event "saving" berjalan untuk CREATE dan UPDATE
        static::saving(function ($model) {
            // Pastikan ada user yang login untuk menghindari error
            if (Auth::check()) {
                // Set user_id dengan ID user yang sedang melakukan aksi
                $model->business_id = Auth::user()->business_id;
            }
        });
    }


public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

//     public function shipments()
// {
//     // Satu kendaraan bisa memiliki banyak riwayat pengiriman
//     return $this->hasMany(Shipment::class);
// }

public function shipments()
    {
        return $this->belongsToMany(Shipment::class, 'shipment_fleet')
                    ->using(ShipmentFleet::class)
                    ->withPivot('driver_name', 'status', 'notes')
                    ->withTimestamps();
    }

}
