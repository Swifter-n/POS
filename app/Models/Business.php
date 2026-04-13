<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Business extends Model
{
    use HasFactory, SoftDeletes;

     protected $fillable = [
        'name',
        'address',
        'phone',
        'bank_name',
        'acc_bank',
        'status',
        'user_id',
    ];

    protected static function booted()
    {
        // Event "saving" berjalan untuk CREATE dan UPDATE
        static::saving(function ($model) {
            // Pastikan ada user yang login untuk menghindari error
            if (Auth::check()) {
                // Set user_id dengan ID user yang sedang melakukan aksi
                $model->user_id = Auth::id();
            }
        });
    }

    /**
     * Get the user that owns the business.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the outlets for the business.
     */
    public function outlets()
    {
        return $this->hasMany(Outlet::class);
    }

    public function inventory()
    {
        return $this->hasMany(Inventory::class);
    }

    public function shippingRates()
{
    return $this->hasMany(ShippingRate::class);
}

    public function shippingRoutes()
{
    return $this->hasMany(ShipmentRoute::class);
}

}
