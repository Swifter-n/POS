<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use HasFactory;
    protected $guarded = [];
     /**
     * Item ini adalah bagian dari satu Purchase Return.
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /**
     * Item ini menunjuk ke record inventory spesifik (batch) yang diretur.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * Relasi ke data master produk.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

}
