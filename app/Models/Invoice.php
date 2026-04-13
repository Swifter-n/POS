<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;
    protected $guarded = [];
    public function customer()
    {
         return $this->belongsTo(Customer::class);
    }
    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }
    public function items()
    {
         return $this->hasMany(InvoiceItem::class);
    }
}
