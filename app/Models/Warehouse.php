<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

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

    // public function area()
    // {
    //     return $this->belongsTo(Area::class);
    // }

    // public function locations()
    // {
    //     return $this->hasMany(Location::class);
    // }

    /**
     * Sebuah Warehouse memiliki banyak Location (Area, Rack, Bin, dll).
     */
    public function locations()
    {
        return $this->morphMany(Location::class, 'locatable');
    }

    // public function inventories()
    // {
    //     return $this->morphMany(Inventory::class, 'stockable');
    // }

    public function parent()
{
    return $this->belongsTo(Warehouse::class, 'parent_id');
}

    public function children()
{
    return $this->hasMany(Warehouse::class, 'parent_id');
}

//     public function village()
// {
//     return $this->belongsTo(Village::class);
// }

    public function shippingRoutes()
{
    // Gudang ini menjadi 'source' untuk banyak rute pengiriman
    return $this->hasMany(ShipmentRoute::class, 'source_warehouse_id');
}

    public function users()
{
    return $this->morphMany(User::class, 'locationable');
}

public function stockCounts(): MorphMany
{
    return $this->morphMany(StockCount::class, 'countable');
}

public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

}
