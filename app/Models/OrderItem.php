<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    public $_addons_data;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_size',
        'quantity',
        'price',
        'uom',
        'total',
        'note'
    ];

    protected $casts = [
        'quantity' => 'decimal:4', // Sesuaikan presisi (4) jika perlu
        'price' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function addons()
    {
        return $this->hasMany(OrderItemAddon::class);
    }
}
