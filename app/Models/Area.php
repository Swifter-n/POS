<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Area extends Model
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

    public function sourcePlant()
    {
        return $this->hasMany(Plant::class);
    }

    public function outlet()
    {
        return $this->hasMany(Outlet::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function ratesFrom()
{
    return $this->hasMany(ShippingRate::class, 'from_area_id');
}

    public function ratesTo()
{
    return $this->hasMany(ShippingRate::class, 'to_area_id');
}

    public function shippingRoutes()
{
    // Area ini menjadi 'destination' untuk banyak rute pengiriman
    return $this->hasMany(ShipmentRoute::class, 'destination_area_id');
}

   /**
     * Area ini dilayani oleh banyak rute pengiriman.
     */
    public function servedByRoutes(): BelongsToMany
    {
        return $this->belongsToMany(ShipmentRoute::class, 'shipment_route_area', 'area_id', 'shipment_route_id')
                    ->using(ShipmentRouteArea::class) // Asumsi Anda punya model pivot
                    ->withPivot('surcharge', 'business_id')
                    ->withTimestamps();
    }

    public function shipmentRoutes(): BelongsToMany
    {
        return $this->belongsToMany(ShipmentRoute::class, 'shipment_route_area')
                    // ==========================================================
                    // --- TAMBAHKAN BARIS INI ---
                    // ==========================================================
                    ->using(ShipmentRouteArea::class)
                    // ==========================================================
                    ->withPivot('surcharge', 'business_id')
                    ->withTimestamps();
    }

        /**
     * Relasi Many-to-Many ke model SalesTeam.
     * Satu area bisa di-cover oleh banyak tim.
     */
    public function salesTeams()
    {
        return $this->belongsToMany(SalesTeam::class, 'area_sales_team_pivot');
    }

}
