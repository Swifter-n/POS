<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Testing\Fluent\Concerns\Has;

class InventoryAdjustment extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function location(): BelongsTo
    {
         return $this->belongsTo(Location::class);
    }
    public function createdBy(): BelongsTo
    {
         return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function plant(): BelongsTo
    {
         return $this->belongsTo(Plant::class, 'plant_id');
    }

    /**
     * Relasi ke Warehouse tempat adjustment ini terjadi.
     */
    public function warehouse(): BelongsTo
    {
         return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * Relasi ke dokumen Stock Count yang memicu adjustment ini.
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function items(): HasMany
    {
         return $this->hasMany(InventoryAdjustmentItem::class);
    }
}
