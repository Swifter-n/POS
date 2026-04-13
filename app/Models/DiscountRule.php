<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class DiscountRule extends Model
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

        /**
     * Relasi ke bisnis yang memiliki aturan ini.
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Relasi ke channel customer spesifik (jika ada).
     */
    public function customerChannel()
    {
        // Kita sebutkan foreign key secara eksplisit karena nama method berbeda
        return $this->belongsTo(Channel::class, 'customer_channel_id');
    }

    /**
     * Relasi ke customer spesifik (jika ada).
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relasi ke produk spesifik (jika ada).
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relasi ke brand spesifik (jika ada).
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function priorityLevel()
{
    return $this->belongsTo(PriorityLevel::class);
}

}
