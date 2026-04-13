<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Zone extends Model
{
    use HasFactory;

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
     * Kosongkan array guarded untuk mengizinkan mass assignment pada semua field.
     * Ini adalah cara cepat dan umum untuk model master data sederhana.
     */
    protected $guarded = [];

    /**
     * Relasi ke Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
