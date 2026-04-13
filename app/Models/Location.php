<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Location extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected $casts = [
        'is_sellable' => 'boolean',
    ];

    /**
     * Relasi polimorfik ke "pemilik" lokasi (Warehouse atau Outlet).
     */
    public function locatable(): MorphTo
    {
        return $this->morphTo();
    }

    // public function warehouse()
    // {
    //     return $this->belongsTo(Warehouse::class);
    // }

    /**
     * Mendapatkan lokasi induk.
     */
    public function parent()
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    /**
     * Mendapatkan semua lokasi anak.
     */
    public function children()
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    /**
     * Relasi ke inventaris yang disimpan di lokasi ini.
     */
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function zone()
{
    return $this->belongsTo(Zone::class);
}

public function sourceGoodsReturns()
{
    return $this->hasMany(GoodsReturn::class, 'source_location_id');
}

public function destinationGoodsReturns()
{
    return $this->hasMany(GoodsReturn::class, 'destination_location_id');
}

 /**
     * Sebuah lokasi bisa memiliki banyak histori Stock Count.
     */
    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    /**
     * Sebuah lokasi bisa memiliki banyak histori Inventory Adjustment.
     */
    public function inventoryAdjustments(): HasMany
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    /**
     * Relasi ke supplier (hanya jika ini adalah lokasi konsinyasi).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'supplier_id');
    }

    public function barcode()
{
    // 1 Lokasi punya 1 Barcode
    return $this->morphOne(Barcode::class, 'barcodeable');
}

public function isFull(): bool
    {
        // Jika max_pallets 0 atau null, anggap unlimited (seperti Staging Area)
        if ($this->max_pallets <= 0) return false;

        return $this->current_pallets >= $this->max_pallets;
    }


}
