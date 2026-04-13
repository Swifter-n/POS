<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAddon extends Model
{
    protected $fillable = [
        'product_id',
        'addon_id',
        'price',
        'is_active',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function addon()
    {
        return $this->belongsTo(Product::class, 'addon_id');
    }
}
