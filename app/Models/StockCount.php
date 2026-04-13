<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

class StockCount extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = ['assigned_teams' => 'array'];

    protected static function booted()
    {
        // Event "creating" hanya berjalan saat membuat record baru
        static::creating(function ($model) {
            // Otomatis set business_id dan created_by_user_id
            if (Auth::check()) {
                if (!$model->business_id) {
                    $model->business_id = Auth::user()->business_id;
                }
                if (!$model->created_by_user_id) {
                    $model->created_by_user_id = Auth::id();
                }
            }

            // Set status default
            if (!$model->status) {
                $model->status = 'draft';
            }

            // Generate count number jika belum ada
            if (!$model->count_number) {
                 $model->count_number = 'SC-' . date('Ym') . '-' . random_int(1000, 9999);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

       /**
     * Relasi ke Plant (opsional, tapi bagus untuk filter).
     */
    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    /**
     * Relasi ke Zone (opsional, untuk cycle count).
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function countable(): MorphTo
    {
         return $this->morphTo();
    }
    public function createdBy(): BelongsTo
    {
         return $this->belongsTo(User::class, 'created_by_user_id');
    }
    public function postedBy(): BelongsTo
    {
         return $this->belongsTo(User::class, 'posted_by_user_id');
    }
    public function items(): HasMany
    {
         return $this->hasMany(StockCountItem::class);
    }
    public function inventoryAdjustment(): HasOne
    {
         return $this->hasOne(InventoryAdjustment::class);
    }
}
