<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebitNote extends Model
{
     use HasFactory;
    protected $guarded = [];

    /**
     * Setiap Debit Note dimiliki oleh satu Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Setiap Debit Note ditujukan kepada satu Supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_id');
    }

    /**
     * Debit Note ini dibuat dari satu Purchase Return.
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /**
     * Sebuah Debit Note memiliki banyak item detail.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DebitNoteItem::class);
    }
}
