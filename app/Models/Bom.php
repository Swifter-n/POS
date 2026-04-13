<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Bom extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'business_id',
        'product_id', // Produk JADI (output)
        'name',
        'code',
        'notes',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Boot the model.
     * Otomatis mengisi business_id.
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            if (Auth::check() && !$model->business_id) {
                $model->business_id = Auth::user()->business_id;
            }
        });
    }

    /**
     * Relasi ke Produk JADI (output) dari BOM ini.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relasi ke Item/Komponen (input) dari BOM ini.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class);
    }

    /**
     * Relasi ke Business.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
