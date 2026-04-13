<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Plant extends Model
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
        /**
     * Relasi ke Business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Sebuah Plant memiliki banyak Warehouse.
     */
    public function warehouses(): HasMany
    {
        // Pastikan foreign key 'plant_id' ada di tabel warehouses
        return $this->hasMany(Warehouse::class);
    }

    /**
     * Sebuah Plant menyuplai banyak Outlet (jika menggunakan supplying_plant_id).
     */
    public function suppliedOutlets(): HasMany
    {
        // Pastikan foreign key 'supplying_plant_id' ada di tabel outlets
        return $this->hasMany(Outlet::class, 'supplying_plant_id');
    }

    public function village()
{
    return $this->belongsTo(Village::class);
}

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}