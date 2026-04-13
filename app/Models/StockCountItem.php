<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class StockCountItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    // [PENTING] Casting agar data numerik akurat saat dikirim ke JSON
    protected $casts = [
        'system_stock' => 'float',
        'final_counted_stock' => 'float',
        'is_zero_count' => 'boolean',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(StockCountEntry::class);
    }
}
// class StockCountItem extends Model
// {
//     use HasFactory;
//     protected $guarded = [];

//     public function stockCount(): BelongsTo
//     {
//          return $this->belongsTo(StockCount::class);
//     }
//     public function inventory(): BelongsTo
//     {
//          return $this->belongsTo(Inventory::class);
//     }
//     public function product(): BelongsTo
//     {
//          return $this->belongsTo(Product::class);
//     }
//     public function entries(): HasMany
//     {
//          return $this->hasMany(StockCountEntry::class);
//     }
// }
