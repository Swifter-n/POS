<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

class PickingListShipment extends Pivot
{
      // Beri tahu Eloquent nama tabelnya
    protected $table = 'picking_list_shipments';

    // Izinkan mass assignment (karena kita tidak menggunakan create() standar)
    protected $guarded = [];

    // Tabel pivot ini tidak memiliki timestamps (created_at/updated_at)
    public $timestamps = false;

    /**
     * Boot the model.
     * Otomatis mengisi business_id saat pivot record dibuat.
     * Ini adalah kunci untuk memperbaiki error 'Not Null Violation'
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            // Cek jika business_id belum diisi dan user login
            if (Auth::check() && !$model->business_id) {
                // Ambil business_id dari user yang sedang login
                $model->business_id = Auth::user()->business_id;
            }
        });
    }
}
