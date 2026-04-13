<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SupplierInvoice extends Model
{
    use HasFactory;
    protected $guarded = [];

    /**
     * Relasi ke Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Relasi ke Supplier (Vendor).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_id');
    }

    /**
     * Relasi polimorfik ke dokumen sumber yang memicu invoice ini
     * (bisa SalesOrder, Order, StockCount, dll).
     */
    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relasi ke item-item detail di dalam invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }
}
