<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PickingList extends Model
{
     use HasFactory;
    protected $guarded = ['id'];
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relasi ke User (Picker)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relasi polimorfik ke Dokumen Sumber (SO atau STO)
    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    // Relasi ke Item (Snapshot)
    public function items(): HasMany
    {
        // Sesuaikan nama model jika berbeda
        return $this->hasMany(PickingListItem::class);
    }

    // Relasi ke Sumber Alokasi (Instruksi FEFO)
    public function sources(): HasMany
    {
        // Asumsi ini relasi ke PickingListItemSource
        return $this->hasMany(PickingListItemSource::class);
    }

    // Relasi ke Gudang tempat picking
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // ==========================================================
    // --- RELASI INI DIPERBARUI ---
    // ==========================================================
    /**
     * Relasi M2M ke Shipment (1 PL hanya bisa punya 1 Shipment).
     */
    public function shipments(): BelongsToMany
    {
        // GANTI 'picking_list_shipment' MENJADI 'picking_list_shipments'
        return $this->belongsToMany(Shipment::class, 'picking_list_shipments')
                    ->using(PickingListShipment::class)
                    ->withPivot('business_id');
    }
}
