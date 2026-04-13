<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $guarded = ['id'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getQuantityRemainingAttribute()
{
    return max(0, $this->quantity - $this->quantity_received);
}
}
