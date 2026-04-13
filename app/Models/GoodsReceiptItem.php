<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $guarded = ['id'];

    public function goodsReceipt()
    {
         return $this->belongsTo(GoodsReceipt::class);
    }
    public function product()
    {
         return $this->belongsTo(Product::class);
    }
}
