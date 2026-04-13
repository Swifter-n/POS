<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $guarded = ['id'];

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Mendefinisikan relasi ke lokasi TUJUAN (jika ada, misal untuk Put-Away).
     */
    public function destinationLocation()
    {
        // Asumsi nama foreign key di tabel stock_transfer_items
        // adalah 'destination_location_id'
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function putAwayEntries()
{
    return $this->hasMany(PutAwayEntry::class);
}

// Helper untuk menghitung total yg sudah di-log
public function getTotalQuantityMovedAttribute(): float
{
    // 'quantity_moved' adalah nama kolom di tabel put_away_entries
    return $this->putAwayEntries()->sum('quantity_moved');
}

// Helper untuk menghitung sisa qty (dalam Base UoM)
public function getRemainingQuantityToPutAwayAttribute(): float
{
    // Asumsi 'quantity' dan 'uom' ada di model ini,
    // dan Anda punya helper untuk konversi ke Base UoM.
    $totalRequiredBase = $this->getQtyInBaseUom(); // Gunakan helper yg sudah Anda buat
    $totalMoved = $this->getTotalQuantityMovedAttribute();
    return $totalRequiredBase - $totalMoved;
}

public function suggestedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'suggested_location_id');
    }

}
