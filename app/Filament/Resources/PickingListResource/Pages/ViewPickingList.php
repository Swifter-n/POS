<?php

namespace App\Filament\Resources\PickingListResource\Pages;

use App\Filament\Resources\PickingListResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\ProductionOrder;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ViewPickingList extends ViewRecord
{
    use HasPermissionChecks;
    protected static string $resource = PickingListResource::class;

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();
        return [
            /**
             * AKSI 1: MEMULAI TUGAS PICKING
             */
            Action::make('startPick')
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
            Action::make('completePick')
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
                                $prodStagingZone = Zone::whereIn('code', ['LINE-A', 'PROD-STG'])->first();
                                if (!$prodStagingZone) throw new \Exception("No 'LINE-A' or 'PROD-STG' Zone found for production staging.");

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
        ];
    }

    /**
     * Eager load semua relasi yang akan ditampilkan untuk performa optimal.
     */
    public function getRecord(): Model
    {
        return parent::getRecord()->load([
            'sourceable',
            'user',
            'items.product',
            'items.sources.inventory.location'
        ]);
    }


    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Picking List Details')
                    ->schema([
                        TextEntry::make('picking_list_number'),
                        // --- FIX: Tampilkan nomor dokumen sumber secara dinamis ---
                        TextEntry::make('sourceable_document_number')
                            ->label('Source Document')
                            ->getStateUsing(function (Model $record) {
                                // getRecord() di atas sudah me-load 'sourceable'
                                if ($record->sourceable instanceof ProductionOrder) {
                                    return $record->sourceable->production_order_number;
                                }
                                if ($record->sourceable instanceof SalesOrder) {
                                    return $record->sourceable->so_number;
                                }
                                // ==================================================
                                // --- INI ADALAH PERBAIKAN YANG ANDA MINTA ---
                                // ==================================================
                                if ($record->sourceable instanceof StockTransfer) {
                                    return $record->sourceable->transfer_number;
                                }
                                return 'N/A';
                            }),
                        TextEntry::make('user.name')->label('Assigned To'),
                        TextEntry::make('status')->badge(),
                    ])->columns(2),

                Section::make('Items to Pick')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.name') // FIX: 'product.name' bukan 'rawMaterial.name'
                                    ->label('Product to Pick')
                                    ->weight('bold'),
                                TextEntry::make('total_quantity_to_pick')
                                    ->label('Total to Pick')
                                    ->suffix(fn ($record) => " {$record->uom}"),

                                RepeatableEntry::make('sources')
                                    ->label('Pick From (FEFO Instruction)')
                                    ->columnSpanFull()
                                    ->schema([
                                        TextEntry::make('inventory.location.name')->label('Location'),
                                        TextEntry::make('inventory.batch')->label('Batch'),
                                        TextEntry::make('inventory.sled')->label('Exp. Date')->date(),
                                        TextEntry::make('quantity_to_pick_from_source')->label('Qty to Pick'),
                                    ])
                                    ->columns(4),
                            ]),
                    ]),
            ]);
    }

}
