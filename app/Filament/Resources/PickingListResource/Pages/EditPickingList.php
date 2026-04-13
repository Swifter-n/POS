<?php

namespace App\Filament\Resources\PickingListResource\Pages;

use App\Filament\Resources\PickingListResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\PickingList;
use App\Models\ProductionOrder;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditPickingList extends EditRecord
{
    use HasPermissionChecks;
    protected static string $resource = PickingListResource::class;

    protected function getFormActions(): array
    {
        return [];
    }


    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();
        return [
            /**
             * AKSI 1: MEMULAI TUGAS PICKING
             */
            Actions\Action::make('startPick')
                ->label('Start Pick')
                ->color('info')->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->visible(fn ($record) =>
                    $record->status === 'pending' &&
                    $record->user_id === $user->id &&
                    $this->check($user, 'pick items') // Ganti nama permission jika perlu
                )
                ->action(function () {
                    try {
                        $record = $this->getRecord();
                        $record->load('items.sources.inventory.product'); // Eager load

                        // --- LOGIKA BARU: VALIDASI STOK SEBELUM MULAI ---
                        foreach ($record->items as $item) {
                            foreach ($item->sources as $source) {
                                $inventory = $source->inventory;
                                $needed = $source->quantity_to_pick_from_source;

                                // Cek ulang stok saat ini di batch spesifik
                                if ($inventory->avail_stock < $needed) {
                                    throw new \Exception("Stock for {$item->product->name} (Batch: {$inventory->batch}) is no longer sufficient. Please regenerate the picking list.");
                                }
                            }
                        }
                        // --- AKHIR LOGIKA BARU ---

                        // Jika semua stok aman, baru update status
                        $record->update([
                            'status' => 'in_progress',
                            'started_at' => now(), // Mencatat waktu mulai
                        ]);
                        Notification::make()->title('Picking started!')->success()->send();
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                    } catch (\Exception $e) {
                        Notification::make()->title('Cannot Start Pick!')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

            /**
             * AKSI 2: SELESAI PICKING & PINDAH KE STAGING
             * (Logika lengkap untuk memindahkan stok)
             */
            Actions\Action::make('completePick')
                ->label('Complete Pick & Move')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn ($record) =>
                    $record->status === 'in_progress' &&
                    $record->user_id === $user->id &&
                    $this->check($user, 'pick items') // Ganti nama permission jika perlu
                )
                ->action(function () use ($record) { // Terima $record
                    try {
                        DB::transaction(function () use ($record) {
                            // Eager load semua relasi yang dibutuhkan
                            $record->load('items.product', 'items.sources.inventory.location.zone', 'sourceable'); // Tambah load 'zone'

                            // VALIDASI BARU: Cek apakah semua item sudah di-input
                            $unpickedItemCount = $record->items()->whereNull('quantity_picked')->count();
                            if ($unpickedItemCount > 0) {
                                throw new \Exception("Terdapat {$unpickedItemCount} item yang belum di-input 'Qty Picked'-nya. Harap isi semua form terlebih dahulu.");
                            }

                            $warehouseId = $record->warehouse_id;
                            if (!$warehouseId) {
                                throw new \Exception("Picking List ini tidak terhubung ke Warehouse ID.");
                            }

                            $sourceable = $record->sourceable;
                            if (!$sourceable) {
                                throw new \Exception("Picking List ini tidak terhubung ke dokumen sumber (SO/STO/PO).");
                            }

                            // ==========================================================
                            // LANGKAH 1: TENTUKAN LOKASI TUJUAN (DINAMIS via ZONE)
                            // ==========================================================
                            $destinationLocation = null; // Untuk Prod/STO Owned
                            $destinationConsLocation = null; // Untuk STO Consignment
                            $movementInType = null;
                            $finalSourceableStatus = null;

                            // Cek Tipe Sumber (Production Order)
                            if ($sourceable instanceof ProductionOrder) {
                                // Cari Zona Staging Produksi (LINE-A atau PROD-STG)
                                // (Logika ini tetap sama, karena staging produksi spesifik)
                                $prodStagingZone = Zone::whereIn('code', ['RM','LINE-A', 'PROD-STG'])->first();
                                if (!$prodStagingZone) throw new \Exception("No 'RM' or 'LINE-A' or 'PROD-STG' Zone found for production staging.");

                                $destinationLocation = Location::where('locatable_type', Warehouse::class)
                                    ->where('locatable_id', $warehouseId)
                                    ->where('zone_id', $prodStagingZone->id)
                                    ->where('status', true)
                                    ->where('is_sellable', false) // Staging tidak boleh sellable
                                    ->first();
                                if (!$destinationLocation) throw new \Exception("No active, non-sellable Location found in Zone '{$prodStagingZone->code}' for Warehouse ID {$warehouseId}.");

                                $movementInType = 'PROD_STAGING_IN';
                                $finalSourceableStatus = 'ready_to_produce';
                            }
                            // Cek Tipe Sumber (Sales Order atau Stock Transfer)
                            elseif ($sourceable instanceof SalesOrder || $sourceable instanceof StockTransfer) {

                                // ==========================================================
                                // --- LOGIKA BARU BERDASARKAN ZONE 'STG' ---
                                // ==========================================================

                                // 1. Cari Zone 'STG'
                                $stagingZone = Zone::where('code', 'STG')->first();
                                if (!$stagingZone) {
                                    throw new \Exception("Zone 'STG' (untuk Outbound Staging) not found in Zones table.");
                                }
                                Log::info("Using Staging Zone ID: {$stagingZone->id}");

                                // 2. Cari Staging Outbound (Owned)
                                $destinationLocation = Location::where('locatable_id', $warehouseId)
                                    ->where('locatable_type', Warehouse::class)
                                    ->where('zone_id', $stagingZone->id)      // <-- Berdasarkan Zone
                                    ->where('ownership_type', 'owned') // <-- Berdasarkan Tipe
                                    ->where('is_sellable', false)      // <-- Staging tidak boleh sellable
                                    ->where('status', true)
                                    ->first();

                                // Data Anda (ID 30) memiliki code 'GPFG-STG' dan type 'owned', ini akan cocok
                                if (!$destinationLocation) {
                                    throw new \Exception("No active, non-sellable, 'owned' Location found in Zone 'STG' for Warehouse ID {$warehouseId}. Please check your setup.");
                                }

                                // 3. Cari Staging Outbound (Consignment)
                                $destinationConsLocation = Location::where('locatable_id', $warehouseId)
                                    ->where('locatable_type', Warehouse::class)
                                    ->where('zone_id', $stagingZone->id)      // <-- Berdasarkan Zone
                                    ->where('ownership_type', 'consignment') // <-- Berdasarkan Tipe
                                    ->where('is_sellable', false)      // <-- Staging tidak boleh sellable
                                    ->where('status', true)
                                    ->first();
                                // (Kita tidak error jika $stagingCons null, krn mungkin tidak ada)

                                $movementInType = 'STAGING_IN';
                                $finalSourceableStatus = 'ready_to_ship';
                                // ==========================================================

                                Log::info("Picking destination determined: Outbound Staging (Owned ID: {$destinationLocation->id}, Cons ID: {$destinationConsLocation?->id})");

                            } else {
                                throw new \Exception("Unsupported sourceable type: " . get_class($sourceable));
                            }


                            // ==========================================================
                            // LANGKAH 2: LOOP SEMUA ITEM DAN PINDAHKAN STOK
                            // (Logika ini tidak berubah, sudah benar)
                            // ==========================================================
                            foreach ($record->items as $item) {
                                $totalQtyToMove = (int)$item->quantity_picked;
                                if ($totalQtyToMove <= 0) {
                                    continue; // Lewati item yg di-pick 0
                                }
                                $remainingQtyToMove = $totalQtyToMove;

                                foreach ($item->sources as $source) {
                                    if ($remainingQtyToMove <= 0) break;
                                    $sourceInventory = $source->inventory;
                                    $allocatedFromThisSource = (int)$source->quantity_to_pick_from_source;
                                    $actualQtyToMoveFromThisSource = min($remainingQtyToMove, $allocatedFromThisSource);

                                    if ($sourceInventory->avail_stock < $actualQtyToMoveFromThisSource) {
                                        throw new \Exception("Stock for {$item->product->name} (Batch: {$sourceInventory->batch}) is no longer available.");
                                    }

                                    // 3. Kurangi stok dari Lokasi Rak/Bin (sumber)
                                    $sourceInventory->decrement('avail_stock', $actualQtyToMoveFromThisSource);
                                    InventoryMovement::create([
                                        'inventory_id' => $sourceInventory->id,
                                        'quantity_change' => -$actualQtyToMoveFromThisSource,
                                        'stock_after_move' => $sourceInventory->avail_stock,
                                        'type' => 'PICKING_OUT', 'reference_type' => get_class($record),
                                        'reference_id' => $record->id, 'user_id' => Auth::id()
                                    ]);

                                    // 4. TENTUKAN LOKASI TUJUAN SPESIFIK
                                    $finalDestination = $destinationLocation; // Default (Owned/Prod Staging)

                                    // Override jika ini STO/SO DAN stoknya konsinyasi
                                    if ($movementInType === 'STAGING_IN' && $sourceInventory->location->ownership_type === 'consignment') {
                                        if (!$destinationConsLocation) {
                                            throw new \Exception("A 'consignment' staging location (in Zone 'STG') not found, but consignment stock was picked!");
                                        }
                                        $finalDestination = $destinationConsLocation;
                                    }

                                    // 5. Tambah stok ke Lokasi Staging yang benar
                                    $stagingInventory = Inventory::firstOrCreate(
                                        [
                                            'location_id' => $finalDestination->id, // <-- Lokasi tujuan dinamis
                                            'product_id' => $sourceInventory->product_id,
                                            'batch' => $sourceInventory->batch,
                                        ],
                                        ['sled' => $sourceInventory->sled, 'avail_stock' => 0, 'business_id' => $record->business_id]
                                    );
                                    $stagingInventory->increment('avail_stock', $actualQtyToMoveFromThisSource);
                                    InventoryMovement::create([
                                        'inventory_id' => $stagingInventory->id,
                                        'quantity_change' => $actualQtyToMoveFromThisSource,
                                        'stock_after_move' => $stagingInventory->avail_stock,
                                        'type' => $movementInType, // <-- Tipe dinamis
                                        'reference_type' => get_class($record),
                                        'reference_id' => $record->id, 'user_id' => Auth::id()
                                    ]);

                                    $remainingQtyToMove -= $actualQtyToMoveFromThisSource;
                                } // Akhir loop sources
                            } // Akhir loop items

                            // 6. UPDATE STATUS (DINAMIS)
                            $record->update(['status' => 'completed', 'completed_at' => now()]);
                            $sourceable->update(['status' => $finalSourceableStatus]); // ready_to_ship ATAU ready_to_produce

                        }); // Akhir DB Transaction

                        Notification::make()->title('Picking complete!')->body('Items have been moved to the appropriate staging area.')->success()->send();

                        // Redirect (Sesuai perbaikan kita sebelumnya, ini lebih aman)
                        //$this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                    } catch (\Exception $e) {
                        Log::error("CompletePick Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()->title('Picking Failed!')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

            Actions\Action::make('cancelPick')
                ->label('Cancel Pick')
                ->color('danger')->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->modalHeading('Cancel Completed Pick')
                ->modalDescription('Are you sure you want to cancel this picking list? This will revert all stock movements and reset the source document.')
                // Hanya muncul jika status 'completed' (selesai) TAPI belum 'shipped'
                ->visible(fn ($record) =>
                    $record->status === 'completed' &&
                    // Ganti permission jika perlu
                    $this->check($user, 'cancel picking list')
                )
                ->action(function () use ($record) {
                    try {
                        DB::transaction(function () use ($record) {
                            $record->load('sourceable');
                            $userId = Auth::id();

                            // 1. Cari semua pergerakan stok yang dibuat oleh Picking List ini
                            $movements = InventoryMovement::where('reference_type', PickingList::class)
                                ->where('reference_id', $record->id)
                                ->orderBy('id', 'desc') // Proses terbalik (opsional tapi aman)
                                ->get();

                            // Ambil pergerakan MASUK (STAGING_IN, PROD_STAGING_IN)
                            $inMovements = $movements->where('quantity_change', '>', 0);
                            // Ambil pergerakan KELUAR (PICKING_OUT)
                            $outMovements = $movements->where('quantity_change', '<', 0);

                            // 2. Validasi Stok di Staging (Pastikan stok belum dipakai)
                            foreach ($inMovements as $inMovement) {
                                $inventory = $inMovement->inventory; // Stok di Staging/Line-A
                                $qtyToRevert = $inMovement->quantity_change; // Qty positif
                                if (!$inventory || $inventory->avail_stock < $qtyToRevert) {
                                    throw new \Exception("Cannot cancel: Stock for batch {$inventory->batch} in staging area {$inventory->location->name} is no longer sufficient (Available: {$inventory?->avail_stock}, Needed: {$qtyToRevert}).");
                                }
                            }

                            // 3. Kembalikan Stok (Revert IN Movements)
                            foreach ($inMovements as $inMovement) {
                                $inventory = $inMovement->inventory;
                                $qtyToRevert = $inMovement->quantity_change;

                                $inventory->decrement('avail_stock', $qtyToRevert);

                                InventoryMovement::create([
                                    'inventory_id' => $inventory->id,
                                    'quantity_change' => -$qtyToRevert, // Jadi negatif
                                    'stock_after_move' => $inventory->avail_stock,
                                    'type' => 'PICK_CANCEL_OUT', // Tipe baru
                                    'reference_type' => get_class($record),
                                    'reference_id' => $record->id,
                                    'user_id' => $userId,
                                    'notes' => 'Reversal from cancellation'
                                ]);
                            }

                            // 4. Kembalikan Stok (Revert OUT Movements)
                            foreach ($outMovements as $outMovement) {
                                $inventory = $outMovement->inventory; // Stok di Bin/Rak
                                $qtyToRevert = abs($outMovement->quantity_change); // Jadi positif

                                $inventory->increment('avail_stock', $qtyToRevert);

                                InventoryMovement::create([
                                    'inventory_id' => $inventory->id,
                                    'quantity_change' => $qtyToRevert, // Jadi positif
                                    'stock_after_move' => $inventory->avail_stock,
                                    'type' => 'PICK_CANCEL_IN', // Tipe baru
                                    'reference_type' => get_class($record),
                                    'reference_id' => $record->id,
                                    'user_id' => $userId,
                                    'notes' => 'Reversal from cancellation'
                                ]);
                            }

                            // 5. Update Status
                            $record->update(['status' => 'cancelled']);
                            // Kembalikan status SO/STO/PO ke 'approved' agar bisa di-pick ulang
                            $record->sourceable->update(['status' => 'approved']);

                        }); // Akhir DB Transaction

                        Notification::make()->title('Picking List Cancelled!')->body('Stock has been returned to its original locations. The source document is ready for reprocessing.')->warning()->send();

                        $this->getRecord()->refresh();
                        $this->refreshFormData($this->getRecord()->toArray());

                    } catch (\Exception $e) {
                        Log::error("CancelPick Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()->title('Cancellation Failed!')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),
        ];
    }
}
