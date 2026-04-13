<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\Fluent\Concerns\Has;

class Outlet extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted()
    {
    static::creating(function ($model) {
        if (Auth::check() && Auth::user()->business_id) {
            $model->business_id = Auth::user()->business_id;
        }
    });
    }

     /**
     * Secara dinamis menentukan level prioritas customer.
     */
    public function getPriorityLevelAttribute()
    {
        // Ambil semua level, diurutkan dari yang paling tinggi
        $levels = PriorityLevel::where('business_id', $this->business_id)
                                ->orderBy('level_order', 'desc')
                                ->get();

        // Cari level pertama yang memenuhi kriteria
        foreach ($levels as $level) {
            if ($this->total_order_count >= $level->min_orders && $this->total_spend >= $level->min_spend) {
                return $level; // Kembalikan objek PriorityLevel
            }
        }

        return null; // Atau level default jika tidak ada yang cocok
    }

    /**
     * Get the business that owns the outlet.
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    // public function inventories()
    // {
    //     return $this->morphMany(Inventory::class, 'stockable');
    // }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function supplyingPlant(): BelongsTo // <-- Ganti nama fungsi & return type
    {
        return $this->belongsTo(Plant::class, 'supplying_plant_id'); // <-- Ganti foreign key
    }

//     public function supplyingWarehouse()
// {
//     return $this->belongsTo(Warehouse::class, 'supplying_warehouse_id');
// }

    public function village()
{
    return $this->belongsTo(Village::class);
}

public function users()
{
    return $this->morphMany(User::class, 'locationable');
}

    /**
     * Sebuah Outlet memiliki satu (atau lebih) Location untuk menyimpan stoknya.
     */
    public function locations()
    {
        return $this->morphMany(Location::class, 'locatable');
    }

    /**
     * Helper untuk mendapatkan SLoc utama milik outlet dengan mudah.
     * Kita asumsikan SLoc utama di outlet memiliki kode 'MAIN'.
     */
    public function mainLocation()
    {
        return $this->morphOne(Location::class, 'locatable')->where('code', 'MAIN');
    }

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

   public function barcodes(): MorphMany
    {
        return $this->morphMany(Barcode::class, 'barcodeable');
    }

}
