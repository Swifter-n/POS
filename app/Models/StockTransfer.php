<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];

    protected $casts = [
        'request_date' => 'datetime',
        'ship_date' => 'datetime',
        'receive_date' => 'datetime',
    ];

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toStockable()
    {
        return $this->morphTo('to_stockable');
    }

     /**
     * Mendefinisikan relasi ke lokasi SUMBER.
     */
    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    /**
     * Mendefinisikan relasi ke lokasi TUJUAN.
     */
    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    /**
     * Sebuah Stock Transfer bisa memiliki satu Shipment.
     */
public function shipments()
    {
        return $this->morphedByMany(
            Shipment::class, // Model target
            'sourceable', // Nama 'name' polimorfik
            'shipment_sourceables', // Nama tabel pivot
            'sourceable_id', // Foreign key di pivot untuk model INI
            'shipment_id' // Foreign key di pivot untuk model TARGET
        )
        ->using(ShipmentSourceable::class) // Gunakan model pivot kustom
        ->withPivot('business_id');
    }

    /**
     * Relasi ke item-item yang ditransfer.
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function pickingLists(): MorphMany
    {
        return $this->morphMany(PickingList::class, 'sourceable');
    }

    /**
     * Helper untuk mengecek apakah Stock Transfer ini adalah Put-Away Task.
     */
    public function isPutAwayTask(): bool
    {
        // Gunakan field 'transfer_number' dari model ini sendiri
        return str_starts_with($this->transfer_number, 'PA-');
    }

        /**
     * Relasi ke User yang meng-approve.
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Relasi ke User yang di-assign (Picker/Staf Gudang).
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

        public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

     public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'plant_id');
    }

    /**
     * Relasi ke Plant sumber (jika STO Eksternal).
     */
    public function sourcePlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'source_plant_id');
    }

    /**
     * Relasi ke Plant tujuan (jika STO Eksternal).
     */
    public function destinationPlant(): BelongsTo
    {
        return $this->belongsTo(Plant::class, 'destination_plant_id');
    }

    /**
     * Relasi ke Outlet tujuan (jika STO Eksternal).
     */
    public function destinationOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'destination_outlet_id');
    }

    public function putAwayEntries()
{
    return $this->hasMany(PutAwayEntry::class);
}

}
