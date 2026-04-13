<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Inventory extends Model
{
    use HasFactory;
    protected $guarded = ['id'];
    protected $casts = [
    'avail_stock' => 'float',
    'sled' => 'date',
];

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

    // public function stockable()
    // {
    //      return $this->morphTo();
    // }

    public function product()
    {
         return $this->belongsTo(Product::class);
    }
    // public function location()
    // {
    //      return $this->belongsTo(Location::class);
    // }
    public function inventoryMovements()
    {
         return $this->hasMany(InventoryMovement::class);
    }

     /**
     * Setiap record inventory berada di satu lokasi spesifik.
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function purchaseReturnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

     /**
     * Sebuah batch inventory bisa muncul di banyak detail Stock Count.
     */
    public function stockCountItems(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    /**
     * Sebuah batch inventory bisa di-adjust berkali-kali.
     */
    public function inventoryAdjustmentItems(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentItem::class);
    }
}
