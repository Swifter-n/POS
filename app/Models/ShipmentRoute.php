<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Auth;

class ShipmentRoute extends Model
{
    use HasFactory;
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

       /**
     * Relasi ke Plant sumber rute.
     */
    public function sourcePlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'source_plant_id');
    }

    /**
     * Relasi ke banyak Area tujuan.
     */
    public function destinationAreas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'shipment_route_area')
                    // ==========================================================
                    // --- TAMBAHKAN BARIS INI ---
                    // ==========================================================
                    ->using(ShipmentRouteArea::class)
                    // ==========================================================
                    ->withPivot('surcharge', 'business_id') // Pastikan business_id ada di sini
                    ->withTimestamps();
    }

}
