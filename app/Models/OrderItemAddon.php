<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemAddon extends Model
{
    protected $fillable = [
        'order_item_id',
        'addon_product_id',
        'quantity',
        'price',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function addonProduct()
    {
        return $this->belongsTo(Product::class, 'addon_product_id');
    }
}
