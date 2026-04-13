<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];
    protected $casts = ['order_date' => 'date', 'expected_delivery_date' => 'date'];


    protected static function booted()
    {
        // Event "saving" berjalan untuk CREATE dan UPDATE
        static::saving(function ($model) {
            // Pastikan ada user yang login untuk menghindari error
            if (Auth::check()) {
                // Set user_id dengan ID user yang sedang melakukan aksi
                $model->business_id = Auth::user()->business_id;
                $model->created_by_user_id = Auth::user()->id;
            }
        });
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }
    // public function warehouse()
    // {
    //     return $this->belongsTo(Warehouse::class);
    // }
    public function vendor()
    {
    return $this->belongsTo(Vendor::class);
    }
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class);
    }

        public function transporter(): BelongsTo
    {
        // Relasi ini menunjuk ke model Vendor, menggunakan foreign key 'transporter_id'
        return $this->belongsTo(Vendor::class, 'transporter_id');
    }

    public function shipments()
{
    return $this->morphToMany(
        Shipment::class,
        'sourceable',
        'shipment_sourceables',
        'sourceable_id',
        'shipment_id'
    )->withPivot('business_id');
}

}
