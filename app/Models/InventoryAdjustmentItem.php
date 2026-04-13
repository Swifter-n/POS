<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentItem extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function inventoryAdjustment(): BelongsTo
    {
         return $this->belongsTo(InventoryAdjustment::class);
    }
    public function inventory(): BelongsTo
    {
         return $this->belongsTo(Inventory::class);
    }
    public function product(): BelongsTo
    {
         return $this->belongsTo(Product::class);
    }
}
