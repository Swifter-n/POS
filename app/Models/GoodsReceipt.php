<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];
    protected $casts = ['receipt_date' => 'date'];

    public function purchaseOrder()
    {
         return $this->belongsTo(PurchaseOrder::class);
    }
    public function warehouse()
    {
         return $this->belongsTo(Warehouse::class);
    }
    public function receivedBy()
    {
         return $this->belongsTo(User::class, 'received_by_user_id');
    }
    public function items()
    {
         return $this->hasMany(GoodsReceiptItem::class);
    }
    public function stockMovements()
    {
        return $this->morphMany(InventoryMovement::class, 'reference');
    }
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

}
