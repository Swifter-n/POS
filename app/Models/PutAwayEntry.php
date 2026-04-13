<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PutAwayEntry extends Model
{
    use HasFactory;

    /**
     * Nama tabel yang terkait dengan model.
     *
     * @var string
     */
    protected $table = 'put_away_entries';

    /**
     * Atribut yang dapat diisi secara massal.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'stock_transfer_id',       // ID dari StockTransfer (Task Induk PA-...)
        'stock_transfer_item_id',  // ID dari StockTransferItem (Baris item yg 2400 pcs)
        'product_id',              // ID produk (untuk kemudahan query)
        'destination_location_id', // Lokasi tujuan (e.g., PALLET-A)
        'quantity_moved',          // Kuantitas dalam Base UoM (e.g., 1500)
        'user_id',                 // User yang melakukan scan/input
    ];

    /**
     * Tipe data cast untuk atribut.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity_moved' => 'decimal:5', // Sesuaikan presisi dengan migrasi Anda
    ];

    /**
     * Mendapatkan relasi ke Task Induk (StockTransfer).
     */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    /**
     * Mendapatkan relasi ke Baris Item (StockTransferItem) yang sedang dikerjakan.
     */
    public function stockTransferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class);
    }

    /**
     * Mendapatkan relasi ke Produk.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Mendapatkan relasi ke Lokasi Tujuan (Pallet/Bin).
     */
    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /**
     * Mendapatkan relasi ke User yang melakukan entri.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
