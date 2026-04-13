<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class PurchasingGroup extends Model
{
    use HasFactory;

    protected $guarded = [];

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

    /**
     * Sebuah Purchasing Group bisa memiliki banyak Vendor/Supplier.
     */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }
}
