<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryMove extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected $casts = [
        'moved_at' => 'datetime',
    ];

    /**
     * Boot the model.
     * Mengisi data default dan memvalidasi stok.
     */
    protected static function booted()
    {
        static::creating(function ($model) {
            // Isi data default
            if (Auth::check()) {
                if (!$model->business_id) $model->business_id = Auth::user()->business_id;
                if (!$model->moved_by_user_id) $model->moved_by_user_id = Auth::id();
            }
            if (!$model->move_number) $model->move_number = 'MV-' . date('Ym') . '-' . random_int(1000, 9999);
            if (!$model->status) $model->status = 'draft'; // Mulai sbg draft
            if (!$model->moved_at) $model->moved_at = now();

            // ==========================================================
            // --- VALIDASI STOK (Krusial) ---
            // ==========================================================
            $inventory = Inventory::find($model->inventory_id);
            if (!$inventory) {
                 Log::error("InventoryMove creating failed: Inventory ID {$model->inventory_id} not found.");
                 throw new \Exception("Inventory batch not found.");
            }
            // Pastikan inventory_id cocok dengan source_location_id
            if ($inventory->location_id != $model->source_location_id) {
                 throw new \Exception("Inventory batch does not exist at the specified source location.");
            }
            // Cek ketersediaan stok
            if ($inventory->avail_stock < $model->quantity_base) {
                throw new \Exception("Insufficient stock for {$inventory->product->name} (Batch: {$inventory->batch}). Available: {$inventory->avail_stock}, Required: {$model->quantity_base}");
            }
        });

        /**
         * Event ini berjalan SETELAH record 'InventoryMove' berhasil dibuat.
         * Ini adalah tempat kita mengeksekusi perpindahan stok.
         */
        static::created(function ($model) {
            try {
                DB::transaction(function () use ($model) {
                    // Kunci inventory sumber (untuk mencegah race condition)
                    $inventory = Inventory::lockForUpdate()->find($model->inventory_id);
                    $quantity = $model->quantity_base;

                    // 1. KURANGI STOK DARI SUMBER
                    $inventory->decrement('avail_stock', $quantity);
                    InventoryMovement::create([
                        'inventory_id' => $inventory->id,
                        'quantity_change' => -$quantity,
                        'stock_after_move' => $inventory->avail_stock,
                        'type' => 'INTERNAL_MOVE_OUT',
                        'reference_type' => get_class($model),
                        'reference_id' => $model->id,
                        'user_id' => $model->moved_by_user_id,
                        'notes' => $model->reason ?? "Ad-hoc move {$model->move_number}",
                    ]);

                    // 2. TAMBAH STOK KE TUJUAN
                    $destinationInventory = Inventory::firstOrCreate(
                        [
                            'location_id' => $model->destination_location_id,
                            'product_id' => $model->product_id,
                            'batch' => $inventory->batch,
                        ],
                        ['sled' => $inventory->sled, 'avail_stock' => 0, 'business_id' => $model->business_id]
                    );
                    $destinationInventory->increment('avail_stock', $quantity);

                    InventoryMovement::create([
                        'inventory_id' => $destinationInventory->id,
                        'quantity_change' => $quantity,
                        'stock_after_move' => $destinationInventory->avail_stock,
                        'type' => 'INTERNAL_MOVE_IN',
                        'reference_type' => get_class($model),
                        'reference_id' => $model->id,
                        'user_id' => $model->moved_by_user_id,
                        'notes' => $model->reason ?? "Ad-hoc move {$model->move_number}",
                    ]);

                    // 3. Update status (jika 'draft')
                    if ($model->status === 'draft') {
                         $model->updateQuietly(['status' => 'completed']);
                    }
                });
            } catch (\Exception $e) {
                 Log::error("InventoryMove (ID: {$model->id}) execution failed: " . $e->getMessage());
                 // Hapus record move yang gagal agar tidak menggantung
                 $model->forceDelete();
                 // Lempar kembali error agar user tahu
                 throw $e;
            }
        });
    }

    // ==========================================================
    // --- RELASI ---
    // ==========================================================

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
    public function plant(): BelongsTo
    {
         return $this->belongsTo(Plant::class);
    }
    public function warehouse(): BelongsTo
    {
         return $this->belongsTo(Warehouse::class);
    }
    public function product(): BelongsTo
    { return $this->belongsTo(Product::class);
    }
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
    public function sourceLocation(): BelongsTo
    {
         return $this->belongsTo(Location::class, 'source_location_id');
    }
    public function destinationLocation(): BelongsTo
    {
         return $this->belongsTo(Location::class, 'destination_location_id');
    }
    public function movedBy(): BelongsTo
    {
         return $this->belongsTo(User::class, 'moved_by_user_id');
    }
}
