<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ShipmentSourceable extends Model
{
     // Beri tahu Eloquent bahwa ini adalah tabel pivot
    protected $table = 'shipment_sourceables';

    // Tidak perlu timestamps (created_at/updated_at) di tabel pivot ini
    public $timestamps = false;

    // Izinkan mass assignment
    protected $guarded = [];

    /**
     * Boot the model.
     * Otomatis mengisi business_id saat pivot record dibuat.
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (Auth::check() && !$model->business_id) {
                // Coba ambil business_id dari Shipment
                if ($model->shipment_id) {
                    $shipment = Shipment::find($model->shipment_id);
                    if ($shipment) {
                        $model->business_id = $shipment->business_id;
                        return; // Selesai
                    }
                }
                // Fallback ke user
                $model->business_id = Auth::user()->business_id;
            }
        });
    }
}
