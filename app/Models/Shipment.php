<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Shipment extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected $casts = [
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'scheduled_for' => 'date',
    ];

    /**
     * Relasi ke bisnis pemilik pengiriman.
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Relasi polimorfik ke dokumen sumber (StockTransfer atau SalesOrder).
     */
        public function sourceable(): MorphTo
{
    return $this->morphTo();
}

    /**
     * Relasi ke armada (truk/kendaraan) yang digunakan.
     */
    public function fleet()
    {
        return $this->belongsTo(Fleet::class);
    }

    /**
     * Detail item yang ada di dalam pengiriman ini.
     */
    public function items()
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function fleets()
    {
        return $this->belongsToMany(Fleet::class, 'shipment_fleet')
                    ->using(ShipmentFleet::class) // Gunakan Pivot Model kustom
                    ->withPivot('driver_name', 'status', 'notes') // Akses kolom tambahan
                    ->withTimestamps();
    }

    public function picker()
    {
    return $this->belongsTo(User::class, 'picker_user_id');
    }

    public function sourcePlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'source_plant_id');
    }

     /**
     * Relasi ke Warehouse sumber pengiriman (tempat picking).
     */
    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    /**
     * Relasi ke Plant tujuan (jika STO Plant-to-Plant).
     */
    public function destinationPlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'destination_plant_id');
    }

    /**
     * Relasi ke Outlet tujuan (jika STO Plant-to-Outlet).
     */
    public function destinationOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'destination_outlet_id');
    }

    public function customer(): BelongsTo
    {
        // Asumsi customer_id di 'contacts'
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Get all Sales Orders that are assigned to this shipment.
     */
    public function salesOrders()
    {
        return $this->morphedByMany(
            SalesOrder::class,
            'sourceable',                // Nama prefix di pivot
            'shipment_sourceables'       // Nama tabel pivot
        );
    }

    /**
     * Get all Stock Transfers that are assigned to this shipment.
     */
    public function stockTransfers()
    {
        return $this->morphedByMany(
            StockTransfer::class,
            'sourceable',                // Nama prefix di pivot
            'shipment_sourceables'       // Nama tabel pivot
        );
    }

public function purchaseOrders()
{
    return $this->morphedByMany(
        PurchaseOrder::class,
        'sourceable',
        'shipment_sourceables'
    )->withPivot('business_id');
}

    public function getSourceablesAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        // Load semua relasi jika belum
        if (!$this->relationLoaded('salesOrders')) { $this->load('salesOrders'); }
        if (!$this->relationLoaded('stockTransfers')) { $this->load('stockTransfers'); }

        // --- TAMBAHAN BARU ---
        if (!$this->relationLoaded('purchaseOrders')) { $this->load('purchaseOrders'); }

        // Gabungkan hasil
        return $this->salesOrders
            ->merge($this->stockTransfers)
            ->merge($this->purchaseOrders); // <-- Gabungkan PO
    }

    // public function getSourceablesAttribute(): \Illuminate\Database\Eloquent\Collection
    // {
    //     // Eager load relasi jika belum di-load
    //     if (!$this->relationLoaded('salesOrders')) {
    //         $this->load('salesOrders');
    //     }
    //     if (!$this->relationLoaded('stockTransfers')) {
    //         $this->load('stockTransfers');
    //     }

    //     // Gabungkan hasil dari kedua relasi
    //     return $this->salesOrders->merge($this->stockTransfers);
    // }

    // ==========================================================
    // --- RELASI INI DIPERBARUI / DITAMBAHKAN ---
    // ==========================================================
    /**
     * Relasi M2M ke Picking List yang digabungkan dalam Shipment ini.
     */
    public function pickingLists(): BelongsToMany
    {
        // GANTI 'picking_list_shipment' MENJADI 'picking_list_shipments'
        return $this->belongsToMany(PickingList::class, 'picking_list_shipments')
                    ->using(PickingListShipment::class)
                    ->withPivot('business_id');
    }

}
