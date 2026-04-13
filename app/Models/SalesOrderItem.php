<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $guarded = ['id'];

    public function salesOrder() {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product() {
        return $this->belongsTo(Product::class);
    }
}
