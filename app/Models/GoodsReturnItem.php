<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReturnItem extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function goodsReturn()
    {
         return $this->belongsTo(GoodsReturn::class);
    }
    public function product()
    {
         return $this->belongsTo(Product::class);
    }
    public function inventory()
    {
         return $this->belongsTo(Inventory::class);
    }
}
