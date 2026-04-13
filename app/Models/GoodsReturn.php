<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\Fluent\Concerns\Has;

class GoodsReturn extends Model
{
    use HasFactory;
    // [PERBAIKAN] Hapus SoftDeletes jika tabel Anda (p-126) tidak memilikinya
    // use HasFactory, SoftDeletes;
    protected $guarded = ['id'];

    // Booted (untuk mengisi default server-side)
    protected static function booted()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                if (!$model->business_id) $model->business_id = Auth::user()->business_id;
                if (!$model->created_by_user_id) $model->created_by_user_id = Auth::id();

                // [PERBAIKAN] Pastikan requested_by_user_id juga diisi jika NOT NULL
                if (!$model->requested_by_user_id) $model->requested_by_user_id = Auth::id();
            }
            if (!$model->status) $model->status = 'draft';
            if (!$model->return_number) $model->return_number = 'GRT-' . date('Ym') . '-' . random_int(1000, 9999);
        });
    }

    // ==========================================================
    // --- RELASI BARU ---
    // ==========================================================
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
    // ==========================================================

    // ==========================================================
    // --- PERBAIKAN: Tambahkan relasi salesOrder() ---
    // ==========================================================
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    // [BARU] Relasi ke Customer (dibutuhkan oleh Resource)
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
    // ==========================================================


    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReturnItem::class); // Asumsi nama model item
    }


    public function requester()
    {
         return $this->belongsTo(User::class, 'requested_by_user_id');
    }
    public function approver()
    {
         return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Sebuah Goods Return bisa memiliki satu Shipment.
     */
    public function shipment(): MorphOne
    {
        return $this->morphOne(Shipment::class, 'sourceable');
    }
}
