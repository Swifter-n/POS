<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseReturn extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * Retur ini dimiliki oleh satu Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Retur ini ditujukan kepada satu Supplier (Vendor).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_id');
    }

    /**
     * Retur ini bisa merujuk pada satu Purchase Order asal.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Retur ini berasal dari satu Warehouse.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Sebuah Purchase Return memiliki banyak item.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

      /**
     * Sebuah Purchase Return dapat menghasilkan satu Debit Note.
     */
    public function debitNote(): HasOne
    {
        return $this->hasOne(DebitNote::class);
    }
    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }
}
