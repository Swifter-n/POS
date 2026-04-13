<?php

namespace App\Filament\Resources\ShipmentResource\Pages;

use App\Events\ConsignmentStockConsumed;
use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Resources\ShipmentResource\Widgets\ShipmentLoadOverview as WidgetsShipmentLoadOverview;
use App\Filament\Widgets\ShipmentLoadOverview;
use App\Models\BusinessSetting;
use App\Models\GoodsReturn;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\ShipmentRoute;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;

class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;
    use HasPermissionChecks;

    protected function getHeaderWidgets(): array
    {
        return [
            WidgetsShipmentLoadOverview::class,
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $source = $this->getRecord()->sourceable;
        if ($source instanceof StockTransfer) {
            $data['source_document_number'] = $source->transfer_number;
        } elseif ($source instanceof SalesOrder) {
            $data['source_document_number'] = $source->so_number;
        }
        return $data;
    }

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();
        return [
            Actions\Action::make('printDo')
            ->label('Print DO')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (Shipment $record): string => route('shipments.print.do', $record))
            ->openUrlInNewTab(),

            Actions\Action::make('markAsShipped')
                ->label(fn (Shipment $record) => $record->purchaseOrders()->exists() ? 'Start Pickup' : 'Mark as Shipped')
                ->color('success')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (Shipment $record) => $record->status === 'ready_to_ship' && $this->check(Auth::user(), 'ship items'))
                ->action(function (Shipment $record) {
                    try {
                        // 1. LOAD RELASI PENTING
                        $record->loadMissing([
                            'items.product',
                            'fleets',
                            'purchaseOrders',
                            'salesOrders',
                            'stockTransfers',
                            'pickingLists'
                        ]);

                        // Deteksi Tipe: Apakah ini Inbound dari Vendor?
                        $isInboundPO = $record->purchaseOrders->isNotEmpty();

                        // 2. VALIDASI UMUM
                        if ($record->fleets()->count() === 0) {
                            throw ValidationException::withMessages(['fleets' => 'Please assign at least one vehicle to this shipment.']);
                        }

                        $totalWeight = $record->items->sum(fn ($item) => ($item->product->weight_kg ?? 0) * $item->quantity);
                        $totalCapacity = $record->fleets()->sum('max_load_kg');

                        if ($totalWeight > $totalCapacity) {
                            throw ValidationException::withMessages(['overload' => "Overload! Load ({$totalWeight} KG) exceeds fleet capacity ({$totalCapacity} KG)."]);
                        }

                        // 3. EKSEKUSI TRANSAKSI
                        DB::transaction(function () use ($record, $isInboundPO) {

                            // ==========================================================
                            // JALUR A: OUTBOUND (Sales Order / Stock Transfer)
                            // Logic: Validasi Gudang -> FEFO Deduction -> SPLIT ITEM BATCH
                            // ==========================================================
                            if (! $isInboundPO) {
                                // --- Validasi Gudang Asal & Staging ---
                                if ($record->sourceables->isEmpty()) {
                                     throw ValidationException::withMessages(['sourceable' => 'Shipment is not linked to any Source Document (SO/STO).']);
                                }

                                $sourceWarehouseId = $record->source_warehouse_id;
                                if (!$sourceWarehouseId) {
                                    // Fallback ke Picking List
                                    $firstPickingList = $record->pickingLists->first();
                                    if ($firstPickingList && $firstPickingList->warehouse_id) {
                                        $sourceWarehouseId = $firstPickingList->warehouse_id;
                                        $record->update(['source_warehouse_id' => $sourceWarehouseId]);
                                    } else {
                                        throw ValidationException::withMessages(['location' => 'Shipment record is missing source_warehouse_id.']);
                                    }
                                }

                                $stagingZone = Zone::where('code', 'STG')->first();
                                if (!$stagingZone) throw ValidationException::withMessages(['location' => "Zone 'STG' not found."]);

                                $stagingOwned = Location::where('locatable_id', $sourceWarehouseId)
                                    ->where('locatable_type', Warehouse::class)
                                    ->where('zone_id', $stagingZone->id)
                                    ->where('ownership_type', 'owned')
                                    ->where('status', true)->first();

                                $stagingCons = Location::where('locatable_id', $sourceWarehouseId)
                                    ->where('locatable_type', Warehouse::class)
                                    ->where('zone_id', $stagingZone->id)
                                    ->where('ownership_type', 'consignment')
                                    ->where('status', true)->first();

                                if (!$stagingOwned) {
                                    throw ValidationException::withMessages(['location' => "No active 'owned' Location found in Zone 'STG' for Warehouse ID {$sourceWarehouseId}."]);
                                }

                                $financialReferenceDoc = $record->sourceables->firstWhere(fn($src) => $src instanceof SalesOrder) ?? $record->sourceables->first();

                                // --- CORE LOGIC: FEFO DEDUCTION & ITEM SPLITTING ---

                                // Kita clone dulu items agar tidak error saat loop sambil delete
                                $originalItems = $record->items;

                                foreach ($originalItems as $item) {
                                    $quantityToShip = $item->quantity;
                                    if ($quantityToShip <= 0) continue;

                                    // Simpan atribut penting sebelum dihapus
                                    $productId = $item->product_id;
                                    $uom = $item->uom;

                                    // 1. Hapus baris item generik (tanpa batch) ini
                                    // Kita akan menggantinya dengan baris-baris baru yang punya Batch/SLED spesifik
                                    $item->delete();

                                    $remainingToShip = $quantityToShip;

                                    // 2. Ambil Inventory STAGING-OWNED (FEFO)
                                    $inventoriesOwned = Inventory::where('location_id', $stagingOwned->id)
                                        ->where('product_id', $productId)
                                        ->where('avail_stock', '>', 0)
                                        ->orderBy('sled', 'asc')
                                        ->get();

                                    foreach ($inventoriesOwned as $inventory) {
                                        if ($remainingToShip <= 0) break;

                                        $qtyFromThisBatch = min($remainingToShip, $inventory->avail_stock);

                                        // Kurangi Stok
                                        $inventory->decrement('avail_stock', $qtyFromThisBatch);

                                        // Log Movement
                                        InventoryMovement::create([
                                            'inventory_id' => $inventory->id,
                                            'quantity_change' => -$qtyFromThisBatch,
                                            'stock_after_move' => $inventory->avail_stock,
                                            'type' => 'SHIPMENT_OUT',
                                            'reference_type' => Shipment::class,
                                            'reference_id' => $record->id,
                                            'user_id' => Auth::id()
                                        ]);

                                        // === UPDATE PENTING: BUAT SHIPMENT ITEM BARU DENGAN BATCH ===
                                        $record->items()->create([
                                            'product_id' => $productId,
                                            'quantity' => $qtyFromThisBatch,
                                            'uom' => $uom,
                                            'batch' => $inventory->batch, // <-- Tracking Batch
                                            'sled' => $inventory->sled,   // <-- Tracking SLED
                                        ]);
                                        // ============================================================

                                        $remainingToShip -= $qtyFromThisBatch;
                                    }

                                    // 3. Ambil Inventory STAGING-CONS (Jika masih kurang)
                                    if ($stagingCons && $remainingToShip > 0) {
                                        $inventoriesCons = Inventory::where('location_id', $stagingCons->id)
                                            ->where('product_id', $productId)
                                            ->where('avail_stock', '>', 0)
                                            ->orderBy('sled', 'asc')
                                            ->get();

                                        $totalAvailCons = $inventoriesCons->sum('avail_stock');
                                        if ($totalAvailCons < $remainingToShip) {
                                             throw ValidationException::withMessages(['stock' => "Insufficient total stock for {$item->product->name} in Staging Area."]);
                                        }

                                        foreach ($inventoriesCons as $inventory) {
                                            if ($remainingToShip <= 0) break;

                                            $qtyFromThisBatch = min($remainingToShip, $inventory->avail_stock);
                                            $inventory->decrement('avail_stock', $qtyFromThisBatch);

                                            InventoryMovement::create([
                                                'inventory_id' => $inventory->id,
                                                'quantity_change' => -$qtyFromThisBatch,
                                                'stock_after_move' => $inventory->avail_stock,
                                                'type' => 'SHIPMENT_OUT',
                                                'reference_type' => Shipment::class,
                                                'reference_id' => $record->id,
                                                'user_id' => Auth::id()
                                            ]);

                                            // Event Konsinyasi
                                            if ($financialReferenceDoc) {
                                                event(new ConsignmentStockConsumed($inventory, $qtyFromThisBatch, $financialReferenceDoc));
                                            }

                                            // === BUAT SHIPMENT ITEM BARU DENGAN BATCH ===
                                            $record->items()->create([
                                                'product_id' => $productId,
                                                'quantity' => $qtyFromThisBatch,
                                                'uom' => $uom,
                                                'batch' => $inventory->batch,
                                                'sled' => $inventory->sled,
                                            ]);
                                            // ============================================

                                            $remainingToShip -= $qtyFromThisBatch;
                                        }
                                    }

                                    if ($remainingToShip > 0) {
                                         throw ValidationException::withMessages(['stock' => "Stock discrepancy for {$item->product->name}. Needed {$quantityToShip}, but only " . ($quantityToShip - $remainingToShip) . " was found in staging."]);
                                    }
                                }
                            }

                            // ==========================================================
                            // JALUR B: INBOUND (Purchase Order)
                            // Logic: Skip inventory & splitting (Barang belum ada batch di sistem)
                            // ==========================================================
                            else {
                                Log::info("Processing Inbound Shipment (PO) #{$record->shipment_number}. Skipping stock deduction.");
                            }

                            // ==========================================================
                            // COMMON LOGIC: UPDATE STATUS
                            // ==========================================================

                            // 1. Update Status Armada
                            foreach ($record->fleets as $fleet) {
                                $fleet->update(['status' => 'in_use']);
                            }
                            if (method_exists($record->fleets(), 'syncWithPivotValues')) {
                                $record->fleets()->syncWithPivotValues($record->fleets->pluck('id')->all(), ['status' => 'shipping']);
                            }

                            // 2. Update Status Shipment
                            $record->update([
                                'status' => 'shipping',
                                'shipped_at' => now(),
                                'shipped_by_user_id' => Auth::id()
                            ]);

                            // 3. Update Status Dokumen Sumber
                            foreach ($record->sourceables as $sourceDoc) {
                                $sourceDoc->update(['status' => 'shipping']);
                            }
                        });

                        $title = $isInboundPO ? 'Pickup Started!' : 'Shipment Marked as Shipped!';
                        $body = $isInboundPO ? 'Driver is on the way to vendor.' : 'Stock deducted & items updated with Batch/SLED info.';

                        Notification::make()->title($title)->body($body)->success()->send();

                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                    } catch (ValidationException $e) {
                        Notification::make()->title('Action Failed')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    } catch (\Exception $e) {
                        Log::error("MarkAsShipped Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()->title('An unexpected error occurred')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

                // Actions\Action::make('markAsShipped')
                //     ->label('Mark as Shipped')
                //     ->color('success')->icon('heroicon-o-paper-airplane')
                //     ->requiresConfirmation()
                //     ->visible(fn ($record) => $record->status === 'ready_to_ship' && $this->check(Auth::user(), 'ship items'))
                //     ->action(function (Shipment $record) {
                //         try {
                //             // 1. PENGECEKAN AWAL (Armada & Kapasitas)
                //             $record->loadMissing('items.product', 'fleets');

                //             if ($record->fleets()->count() === 0) {
                //                 throw ValidationException::withMessages(['fleets' => 'Please assign at least one vehicle to this shipment.']);
                //             }
                //             $totalWeight = $record->items->sum(fn ($item) => ($item->product->weight_kg ?? 0) * $item->quantity);
                //             $totalCapacity = $record->fleets()->sum('max_load_kg');
                //             if ($totalWeight > $totalCapacity) {
                //                 throw ValidationException::withMessages(['overload' => "Overload! Load ({$totalWeight} KG) exceeds fleet capacity ({$totalCapacity} KG)."]);
                //             }

                //             DB::transaction(function () use ($record) {
                //                 // ==========================================================
                //                 // --- PERBAIKAN: Muat relasi M2M yang SEBENARNYA ---
                //                 // ==========================================================
                //                 $record->load(
                //                     'salesOrders',      // <-- Muat relasi ini
                //                     'stockTransfers',   // <-- Muat relasi ini
                //                     'pickingLists'      // <-- DITAMBAHKAN (Untuk fallback)
                //                 );
                //                 // ==========================================================

                //                 // Ambil *satu* referensi SO (jika ada) untuk event finansial
                //                 $financialReferenceDoc = $record->sourceables
                //                     ->firstWhere(fn($src) => $src instanceof SalesOrder);

                //                 if (!$financialReferenceDoc) {
                //                     $financialReferenceDoc = $record->sourceables->first();
                //                 }
                //                 if ($record->sourceables->isEmpty()) {
                //                     throw ValidationException::withMessages(['sourceable' => 'Shipment is not linked to any Source Document (SO/STO).']);
                //                 }

                //                 $sourceWarehouseId = $record->source_warehouse_id;
                //                 if (!$sourceWarehouseId) {
                //                     Log::warning("Shipment ID {$record->id} is missing source_warehouse_id. Attempting fallback to Picking List.");
                //                     // Coba ambil dari Picking List pertama yang terhubung
                //                     $firstPickingList = $record->pickingLists->first();

                //                     if ($firstPickingList && $firstPickingList->warehouse_id) {
                //                         $sourceWarehouseId = $firstPickingList->warehouse_id;
                //                         // (Opsional: Perbarui shipment agar data ini tersimpan)
                //                         $record->update(['source_warehouse_id' => $sourceWarehouseId]);
                //                     } else {
                //                         // Jika fallback juga gagal, baru lempar error
                //                         throw ValidationException::withMessages(['location' => 'Shipment record is missing its source_warehouse_id and it could not be found on the associated Picking List.']);
                //                     }
                //                 }
                //                 // ==========================================================


                //                 // 1. Cari Zone 'STG'
                //                 $stagingZone = Zone::where('code', 'STG')->first();
                //                 if (!$stagingZone) {
                //                     throw ValidationException::withMessages(['location' => "Zone 'STG' (untuk Outbound Staging) not found in Zones table."]);
                //                 }

                //                 // 2. Cari Staging Outbound (Owned)
                //                 $stagingOwned = Location::where('locatable_id', $sourceWarehouseId)
                //                     ->where('locatable_type', Warehouse::class)
                //                     ->where('zone_id', $stagingZone->id)
                //                     ->where('ownership_type', 'owned')
                //                     ->where('is_sellable', false)
                //                     ->where('status', true)
                //                     ->first();

                //                 // 3. Cari Staging Outbound (Consignment)
                //                 $stagingCons = Location::where('locatable_id', $sourceWarehouseId)
                //                     ->where('locatable_type', Warehouse::class)
                //                     ->where('zone_id', $stagingZone->id)
                //                     ->where('ownership_type', 'consignment')
                //                     ->where('is_sellable', false)
                //                     ->where('status', true)
                //                     ->first();

                //                 // 4. Validasi
                //                 if (!$stagingOwned) {
                //                     throw ValidationException::withMessages(['location' => "No active, non-sellable, 'owned' Location found in Zone 'STG' for Warehouse ID {$sourceWarehouseId}. Please check your setup."]);
                //                 }
                //                 // ==========================================================


                //                 // --- LANGKAH B: LOGIKA PENGURANGAN STOK ---
                //                 foreach ($record->items as $item) {
                //                     $quantityToShip = $item->quantity;
                //                     if ($quantityToShip <= 0) continue;
                //                     $remainingToShip = $quantityToShip;

                //                     // Prioritas 1: STAGING-OWNED (Ditemukan via Zone)
                //                     $inventoriesOwned = Inventory::where('location_id', $stagingOwned->id)
                //                         ->where('product_id', $item->product_id)->where('avail_stock', '>', 0)
                //                         ->orderBy('sled', 'asc')->get();

                //                     foreach ($inventoriesOwned as $inventory) {
                //                         if ($remainingToShip <= 0) break;
                //                         $qtyFromThisBatch = min($remainingToShip, $inventory->avail_stock);
                //                         $inventory->decrement('avail_stock', $qtyFromThisBatch);
                //                         InventoryMovement::create([
                //                             'inventory_id' => $inventory->id, 'quantity_change' => -$qtyFromThisBatch,
                //                             'stock_after_move' => $inventory->avail_stock, 'type' => 'SHIPMENT_OUT',
                //                             'reference_type' => Shipment::class, 'reference_id' => $record->id, 'user_id' => Auth::id()
                //                         ]);
                //                         $remainingToShip -= $qtyFromThisBatch;
                //                     }

                //                     // Prioritas 2: STAGING-CONS (Ditemukan via Zone)
                //                     if ($stagingCons && $remainingToShip > 0) {
                //                         $inventoriesCons = Inventory::where('location_id', $stagingCons->id)
                //                             ->where('product_id', $item->product_id)->where('avail_stock', '>', 0)
                //                             ->orderBy('sled', 'asc')->get();

                //                         $totalAvailCons = $inventoriesCons->sum('avail_stock');
                //                         if ($totalAvailCons < $remainingToShip) {
                //                             throw ValidationException::withMessages(['stock' => "Insufficient total stock (Owned + Consignment) for {$item->product->name} in Staging Area."]);
                //                         }

                //                         foreach ($inventoriesCons as $inventory) {
                //                             if ($remainingToShip <= 0) break;
                //                             $qtyFromThisBatch = min($remainingToShip, $inventory->avail_stock);
                //                             $inventory->decrement('avail_stock', $qtyFromThisBatch);
                //                             InventoryMovement::create([
                //                                 'inventory_id' => $inventory->id, 'quantity_change' => -$qtyFromThisBatch,
                //                                 'stock_after_move' => $inventory->avail_stock, 'type' => 'SHIPMENT_OUT',
                //                                 'reference_type' => Shipment::class, 'reference_id' => $record->id, 'user_id' => Auth::id()
                //                             ]);

                //                             if ($financialReferenceDoc) {
                //                                 event(new ConsignmentStockConsumed($inventory, $qtyFromThisBatch, $financialReferenceDoc));
                //                             }
                //                             $remainingToShip -= $qtyFromThisBatch;
                //                         }
                //                     }

                //                     if ($remainingToShip > 0) {
                //                         throw ValidationException::withMessages(['stock' => "Stock discrepancy for {$item->product->name}. Needed {$quantityToShip}, but only " . ($quantityToShip - $remainingToShip) . " was found in staging."]);
                //                     }
                //                 }

                //                 // --- LANGKAH C: UPDATE SEMUA STATUS ---
                //                 // 1. Update Armada
                //                 foreach ($record->fleets as $fleet) {
                //                     $fleet->update(['status' => 'in_use']);
                //                 }
                //                 if (method_exists($record->fleets(), 'syncWithPivotValues')) {
                //                     $record->fleets()->syncWithPivotValues($record->fleets->pluck('id')->all(), ['status' => 'shipping']);
                //                 }

                //                 // 2. Update Shipment (DO)
                //                 $record->update(['status' => 'shipping', 'shipped_at' => now(), 'shipped_by_user_id' => Auth::id()]);

                //                 // 3. Update semua SO/STO yang terhubung
                //                 // (Accessor $record->sourceables akan bekerja)
                //                 foreach ($record->sourceables as $sourceDoc) {
                //                     $sourceDoc->update(['status' => 'shipping']); // Ganti ke 'shipping'
                //                 }
                //             });

                //             Notification::make()->title('Shipment Marked as Shipped!')->body('Stock has been deducted from Staging Area.')->success()->send();

                //             // ==========================================================
                //             // --- PERBAIKAN BUG REFRESH ('array_flip') ---
                //             // =Ganti 'refreshFormData' dengan 'redirect'
                //             // ==========================================================
                //             // $this->getRecord()->refresh(); // <-- DIHAPUS
                //             // $this->refreshFormData($this->getRecord()->toArray()); // <-- DIHAPUS
                //             return redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                //         } catch (ValidationException $e) {
                //             Notification::make()->title('Action Failed')->body($e->getMessage())->danger()->send();
                //             $this->halt();
                //         } catch (\Exception $e) {
                //             // ==========================================================
                //             // --- INI PERBAIKAN SINTAKS PHP ---
                //             // ==========================================================
                //             Log::error("MarkAsShipped Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                //             // ==========================================================
                //             Notification::make()->title('An unexpected error occurred')->body($e->getMessage())->danger()->send();
                //             $this->halt();
                //         }
                //     }),



        Actions\Action::make('receiveStoItems')
                ->label('Receive Items (STO)')
                ->color('success')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->visible(function (Shipment $record): bool {
                    $user = Auth::user();
                    if ($record->status !== 'shipping' || !$user) return false;

                    // Tombol ini HANYA muncul jika ini adalah STO (punya tujuan Plant/Outlet)
                    if (!$record->destination_plant_id && !$record->destination_outlet_id) {
                        return false;
                    }

                    // Cek lokasi user (apakah dia ada di plant/outlet tujuan?)
                    $userPlantId = null;
                    $userOutletId = null;
                    if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
                        $userPlantId = $user->locationable->plant_id;
                    } elseif ($user->locationable_type === \App\Models\Outlet::class && $user->locationable_id) {
                        $userOutletId = $user->locationable_id;
                    }

                    $isDestination = ($record->destination_plant_id == $userPlantId) || ($record->destination_outlet_id == $userOutletId);

                    // Cek permission
                    return $isDestination && $this->check($user, 'receive shipped items');
                })
                ->url(fn (Shipment $record): string =>
                    // Arahkan ke halaman GR kustom yang baru kita buat
                    GoodsReceiptResource::getUrl('receive-shipment', ['shipment' => $record])
                ),


            /**
         * AKSI 2: RECEIVE ITEMS / POD (Untuk Tim Penerima atau Konfirmasi Sales)
         */
        Actions\Action::make('receiveItems')
                ->label(function (Shipment $record): string {
                    $record->loadMissing('salesOrders', 'stockTransfers');
                    if ($record->sourceables->contains(fn($src) => $src instanceof GoodsReturn)) {
                        return 'Receive Returned Items';
                    }
                    return 'Confirm Delivery (POD)';
                })
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(function (Shipment $record) use ($user): bool {
                    if ($record->status !== 'shipping' || !$user) return false;
                    if ($record->customer_id) {
                        return $this->check($user, 'confirm sales order delivery');
                    }
                    $record->loadMissing('salesOrders', 'stockTransfers');
                    if ($record->sourceables->contains(fn($src) => $src instanceof GoodsReturn)) {
                         return $this->check($user, 'receive returned items');
                    }
                    return false;
                })
                ->form(function (Shipment $record): array {
                    $record->loadMissing('salesOrders', 'stockTransfers');
                    $isReturn = $record->sourceables->contains(fn($src) => $src instanceof GoodsReturn);

                    $baseSchema = [
                        Placeholder::make('product_name')
                            ->content(fn(Get $get) => Product::find($get('product_id'))?->name)
                            ->columnSpan(2),
                        Placeholder::make('quantity_shipped_display')
                            ->label('Qty Shipped')
                            ->content(fn(Get $get) => $get('quantity_shipped_display')),
                        TextInput::make('quantity_received')
                            ->numeric()->required()->label('Qty Received (Accepted)')
                            ->minValue(0)
                            ->reactive()
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $product = Product::find($get('product_id'));
                                    if (!$product) return;
                                    $product->loadMissing('uoms');
                                    $inputQty = (float) $value;
                                    $inputUomName = $get('received_uom');
                                    $uomData = $product->uoms->firstWhere('uom_name', $inputUomName);
                                    $conversionRate = $uomData?->conversion_rate ?? 1;
                                    $inputQtyInBase = $inputQty * $conversionRate;
                                    $shippedQtyInBase = (float) $get('quantity_shipped_base');
                                    if (round($inputQtyInBase, 5) > round($shippedQtyInBase, 5)) {
                                        $fail("Qty received ({$inputQtyInBase} base) cannot exceed qty shipped ({$shippedQtyInBase} base).");
                                    }
                                };
                            }),
                        Select::make('received_uom')
                            ->label('UoM')
                            ->options(fn (Get $get) => ProductUom::where('product_id', $get('product_id'))->pluck('uom_name', 'uom_name'))
                            ->required(),
                        Hidden::make('product_id'),
                        Hidden::make('quantity_shipped_base'),
                        Hidden::make('quantity_shipped_display'),
                        Hidden::make('base_uom'),
                    ];
                    if ($isReturn) {
                        $returnSchema = [
                            TextInput::make('batch')->label('New Batch')->required(),
                            DatePicker::make('sled')->label('New SLED')->required(),
                        ];
                        $baseSchema = array_merge($baseSchema, $returnSchema);
                        $columns = 7;
                    } else {
                        $soSchema = [
                            TextInput::make('rejection_reason')
                                ->label('Rejection Reason (if any)')
                                ->visible(fn(Get $get) : bool => (float)($get('quantity_shipped_base') ?? 0) > (float)($get('quantity_received') ?? 0) )
                                ->columnSpanFull()
                        ];
                         $baseSchema = array_merge($baseSchema, $soSchema);
                        $columns = 5;
                    }
                    return [
                        Repeater::make('items_confirmation')
                            ->label('Item Confirmation')
                            ->schema($baseSchema)
                            ->columns($columns)
                            ->addable(false)->deletable(false)
                            ->default(function (Shipment $record) {
                                $record->load('items.product.uoms');
                                $record->load(['salesOrders.items', 'stockTransfers.items']);
                                $sourceItems = $record->sourceables->pluck('items')->flatten();
                                return $record->items->map(function($item) use ($sourceItems) {
                                    $qtyInBaseUom = (float) $item->quantity;
                                    $baseUom = $item->product?->base_uom ?? 'PCS';
                                    $sourceItem = $sourceItems->firstWhere('product_id', $item->product_id);
                                    $originalUom = $sourceItem?->uom ?? $baseUom;
                                    $uomData = $item->product?->uoms->where('uom_name', $originalUom)->first();
                                    $conversionRate = $uomData?->conversion_rate ?? 1;
                                    $displayQuantity = ($conversionRate > 0) ? ($qtyInBaseUom / $conversionRate) : $qtyInBaseUom;
                                    return [
                                        'product_id' => $item->product_id,
                                        'quantity_shipped_base' => $qtyInBaseUom,
                                        'quantity_shipped_display' => round($displayQuantity, 2) . " {$originalUom}",
                                        'quantity_received' => round($displayQuantity, 2),
                                        'received_uom' => $originalUom,
                                        'base_uom' => $baseUom,
                                    ];
                                })->toArray();
                            }),
                    ];
                })
                ->action(function(Shipment $record, array $data) {
                    try {
                        DB::transaction(function() use ($record, $data){
                            $record->load('salesOrders.termsOfPayment', 'stockTransfers');

                            $finalStatus = 'delivered';
                            $userId = Auth::id();
                            $isReturn = $record->sourceables->contains(fn($src) => $src instanceof GoodsReturn);

                            if ($isReturn) {
                                // =================================================================
                                // LOGIKA UNTUK GOODS RETURN
                                // =================================================================

                                // [PERBAIKAN p-138] Gunakan 'RET' bukan 'RETURN'
                                $returnZone = Zone::where('code', 'RET')->first();
                                if (!$returnZone) throw new \Exception('Master data Zone "RET" not found.');

                                $returnLocation = Location::where('locatable_type', Warehouse::class)
                                    ->where('locatable_id', $record->source_warehouse_id)
                                    ->where('zone_id', $returnZone->id)
                                    ->where('status', true)
                                    ->first();
                                if (!$returnLocation) throw new \Exception("No active 'RET' zone location found in source warehouse ID {$record->source_warehouse_id}.");

                                $destinationLocationId = $returnLocation->id;
                                foreach ($data['items_confirmation'] as $index => $itemData) {
                                    $quantityReceivedInput = (float)($itemData['quantity_received'] ?? 0);
                                    if ($quantityReceivedInput <= 0) continue;
                                    $product = Product::find($itemData['product_id']);
                                    if (!$product) continue;
                                    $product->loadMissing('uoms');
                                    $receivedUomName = $itemData['received_uom'];
                                    $uomData = $product->uoms->where('uom_name', $receivedUomName)->first();
                                    if (!$uomData) throw new \Exception("UoM '{$receivedUomName}' not found for product '{$product->name}'.");
                                    $conversionRate = $uomData?->conversion_rate ?? 1;
                                    $quantityReceivedInBaseUom = $quantityReceivedInput * $conversionRate;
                                    $qtyShippedBase = (float) $itemData['quantity_shipped_base'];
                                    if (round($quantityReceivedInBaseUom, 5) > round($qtyShippedBase, 5)) {
                                         throw ValidationException::withMessages([
                                            'items_confirmation.'.$index.'.quantity_received' => "Received qty ({$quantityReceivedInBaseUom} base) cannot exceed shipped qty ({$qtyShippedBase} base)."
                                        ]);
                                    }
                                    $batch = $itemData['batch'] ?? 'RTN-' . $record->shipment_number;
                                    $sled = $itemData['sled'] ?? now()->addYear();
                                    $inventory = Inventory::firstOrCreate(
                                        ['location_id' => $destinationLocationId, 'product_id' => $itemData['product_id'], 'batch' => $batch],
                                        ['sled' => $sled, 'avail_stock' => 0, 'business_id' => $record->business_id]
                                    );
                                    $inventory->increment('avail_stock', $quantityReceivedInBaseUom);
                                    InventoryMovement::create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_change' => $quantityReceivedInBaseUom,
                                        'stock_after_move' => $inventory->avail_stock,
                                        'type' => 'RETURN_IN',
                                        'reference_type' => Shipment::class, 'reference_id' => $record->id, 'user_id' => $userId,
                                        'notes' => "Received {$quantityReceivedInput} {$receivedUomName} from Return DO #{$record->shipment_number} into {$returnLocation->name}",
                                    ]);
                                }
                                $finalStatus = 'received';
                                Notification::make()->title('Return Received!')->body('Stock has been returned to warehouse.')->success()->send();

                            } elseif ($record->customer_id && !$isReturn) {
                                // =================================================================
                                // --- PERBAIKAN: LOGIKA PARTIAL POD UNTUK SALES ORDER ---
                                // =================================================================

                                $sourceDoc = $record->salesOrders->first();
                                if (!$sourceDoc) {
                                    throw new \Exception('Source Sales Order not found for this shipment.');
                                }
                                $sourceDoc->load('items.product.uoms');

                                $invoiceItems = [];
                                $returnItems = [];
                                $totalInvoiceSubtotal = 0;
                                $totalInvoiceDiscount = 0;
                                $hasRejections = false;

                                foreach ($data['items_confirmation'] as $itemData) {
                                    $product = Product::find($itemData['product_id']);
                                    if (!$product) continue;
                                    $product->loadMissing('uoms');

                                    // 1. Ambil data dari form
                                    $inputQty = (float)($itemData['quantity_received'] ?? 0);
                                    $inputUomName = $itemData['received_uom'];
                                    $shippedBaseQty = (float) $itemData['quantity_shipped_base'];

                                    // 2. Konversi input Qty ke Base UoM
                                    $inputUomData = $product->uoms->firstWhere('uom_name', $inputUomName);
                                    $inputConversionRate = $inputUomData?->conversion_rate ?? 1;
                                    $receivedBaseQty = $inputQty * $inputConversionRate;

                                    // 3. Hitung Qty Ditolak (Base UoM)
                                    $rejectedBaseQty = max(0, $shippedBaseQty - $receivedBaseQty);
                                    if (round($rejectedBaseQty, 5) > 0) {
                                        $hasRejections = true;
                                    }

                                    // 4. Ambil data harga dari Sales Order Item asli
                                    $soItem = $sourceDoc->items->firstWhere('product_id', $product->id);
                                    if (!$soItem) {
                                         Log::warning("SO Item not found for Product ID {$product->id} on SO {$sourceDoc->so_number}");
                                         continue;
                                    }

                                    // 5. Hitung harga/diskon per Base UoM
                                    $soItemUomData = $product->uoms->firstWhere('uom_name', $soItem->uom);
                                    $soItemConversionRate = $soItemUomData?->conversion_rate ?? 1;
                                    $pricePerBaseUom = (float)($soItem->price_per_item ?? 0) / $soItemConversionRate;
                                    $discountPerBaseUom = (float)($soItem->discount_per_item ?? 0) / $soItemConversionRate;

                                    // 6. Kumpulkan data untuk INVOICE (hanya yg diterima)
                                    if ($receivedBaseQty > 0) {
                                        $itemSubtotal = $pricePerBaseUom * $receivedBaseQty;
                                        $itemDiscount = $discountPerBaseUom * $receivedBaseQty;
                                        $totalInvoiceSubtotal += $itemSubtotal;
                                        $totalInvoiceDiscount += $itemDiscount;
                                        $invoiceItems[] = [
                                            'product_id' => $product->id,
                                            'uom' => $product->base_uom,
                                            'quantity' => $receivedBaseQty,
                                            'price_per_item' => $pricePerBaseUom,
                                            'discount_per_item' => $discountPerBaseUom,
                                            'total_price' => $itemSubtotal - $itemDiscount,
                                        ];
                                    }

                                    // 7. Kumpulkan data untuk GOODS RETURN (hanya yg ditolak)
                                    if ($rejectedBaseQty > 0) {
                                        $returnItems[] = [
                                            'product_id' => $product->id,
                                            'uom' => $product->base_uom,
                                            'quantity' => $rejectedBaseQty,
                                            'reason' => $itemData['rejection_reason'] ?? 'Ditolak Pelanggan',
                                        ];
                                    }
                                } // --- Akhir Loop Item Form ---

                                // 8. Buat INVOICE (jika ada barang diterima)
                                if (count($invoiceItems) > 0) {
                                    $taxSetting = BusinessSetting::where('type', 'tax')->where('business_id', $record->business_id)->first();
                                    $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
                                    $taxableAmount = $totalInvoiceSubtotal - $totalInvoiceDiscount;
                                    $taxAmount = ($taxableAmount * $taxPercent) / 100;
                                    $shippingCost = $sourceDoc->shipping_cost ?? 0;
                                    $grandTotal = $taxableAmount + $shippingCost + $taxAmount;
                                    $invoice = Invoice::create([
                                        'invoice_number' => 'INV-' . $sourceDoc->so_number,
                                        'sales_order_id' => $sourceDoc->id,
                                        'customer_id' => $sourceDoc->customer_id,
                                        'business_id' => $sourceDoc->business_id,
                                        'invoice_date' => now(),
                                        'due_date' => now()->addDays($sourceDoc->termsOfPayment?->days ?? 0),
                                        'sub_total' => $totalInvoiceSubtotal,
                                        'total_discount' => $totalInvoiceDiscount,
                                        'shipping_cost' => $shippingCost,
                                        'tax' => $taxAmount,
                                        'grand_total' => $grandTotal,
                                        'status' => 'unpaid',
                                    ]);
                                    $invoice->items()->createMany($invoiceItems);
                                    Notification::make()->title('Invoice Created!')->body("Invoice #{$invoice->invoice_number} created for accepted items.")->success()->send();
                                }

                                // 9. Buat GOODS RETURN (jika ada barang ditolak)
                                if (count($returnItems) > 0) {

                                    // ==========================================================
                                    // --- PERBAIKAN (p-125): Tambahkan 'plant_id' ---
                                    // ==========================================================
                                    $goodsReturn = GoodsReturn::create([
                                        'return_number' => 'RTN-SO-' . $sourceDoc->so_number,
                                        'sales_order_id' => $sourceDoc->id,
                                        'customer_id' => $sourceDoc->customer_id,
                                        'business_id' => $sourceDoc->business_id,
                                        'requested_by_user_id' => $userId, // (Fix p-121)
                                        'plant_id' => $record->source_plant_id, // <-- [PERBAIKAN]
                                        'return_date' => now(), // (Fix p-126)
                                        'status' => 'pending',
                                        'notes' => 'Auto-generated from partial rejection on DO #' . $record->shipment_number,
                                    ]);
                                    $goodsReturn->items()->createMany($returnItems);
                                    Notification::make()->title('Goods Return Created!')->body("Return #{$goodsReturn->return_number} created for rejected items.")->warning()->send();
                                }

                                // 10. Tentukan Status Final
                                $finalStatus = $hasRejections ? 'partially_received' : 'delivered';
                            }
                            // =================================================================
                            // --- AKHIR BLOK PERBAIKAN SO ---
                            // =================================================================


                            // LANGKAH FINAL: Update semua status
                            $record->update(['status' => $finalStatus, 'delivered_at' => now()]);

                            if ($record->salesOrders->isNotEmpty()) {
                                $record->salesOrders()->update(['status' => $finalStatus]);
                            }

                            // [PERBAIKAN] Hapus $record->update(['status' => 'completed']);

                            $record->fleets()->update(['status' => 'available']); // Bebaskan armada
                            if (method_exists($record->fleets(), 'syncWithPivotValues')) {
                                $record->fleets()->syncWithPivotValues($record->fleets->pluck('id')->all(), ['status' => 'completed']);
                            }
                        });

                        Notification::make()->title('Success!')->body('Process completed successfully.')->success()->send(); // Notif umum
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                    } catch (ValidationException $e) {
                         Notification::make()->title('Validation Failed')->body($e->getMessage())->danger()->send();
                         $this->halt();
                    } catch (\Exception $e) {
                         Log::error("ReceiveItems Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                         Notification::make()->title('Receive Failed')->body($e->getMessage())->danger()->send();
                         $this->halt();
                    }
                }),


        /**
             * AKSI UNTUK MEMBATALKAN SHIPMENT
             * Dengan logika pengembalian stok ke Lokasi Karantina.
             */
            Actions\Action::make('cancelShipment')
                ->label('Cancel Shipment')
                ->color('danger')->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->visible(fn (Shipment $record) =>
                    !in_array($record->status, ['received', 'delivered', 'cancelled']) &&
                    $this->check(Auth::user(), 'cancel shipments')
                )
                ->action(function (Shipment $record) {
                    try {
                        DB::transaction(function () use ($record) {
                            $record->load(
                                'salesOrders',
                                'stockTransfers',
                                'pickingLists',
                                'fleets',
                                'items.product'
                            );
                            $putAwayItems = new Collection();
                            $cancelLocation = null;
                            if ($record->status === 'shipping') {
                                // ==========================================================
                                // --- PERBAIKAN: Tambahkan fallback logic (dari 'markAsShipped') ---
                                // ==========================================================
                                $sourceWarehouseId = $record->source_warehouse_id;
                                if (!$sourceWarehouseId) {
                                    Log::warning("Shipment ID {$record->id} is missing source_warehouse_id (Cancel Action). Attempting fallback to Picking List.");
                                    $firstPickingList = $record->pickingLists->first();
                                    if ($firstPickingList && $firstPickingList->warehouse_id) {
                                        $sourceWarehouseId = $firstPickingList->warehouse_id;
                                    } else {
                                        throw new \Exception('Cannot determine source warehouse from shipment record or its associated picking lists.');
                                    }
                                }
                                // ==========================================================

                                $rcvZone = Zone::where('code', 'RCV')->first();
                                if (!$rcvZone) throw new \Exception('Master data Zone "RCV" (Receiving) not found.');
                                $rcvLocations = Location::where('locatable_type', Warehouse::class)
                                    ->where('locatable_id', $sourceWarehouseId)
                                    ->where('zone_id', $rcvZone->id)
                                    ->where('status', true)
                                    ->get();
                                $cancelLocation = $rcvLocations->firstWhere('is_default_receiving', true);
                                if (!$cancelLocation && $rcvLocations->count() === 1) {
                                    $cancelLocation = $rcvLocations->first();
                                } elseif (!$cancelLocation) {
                                     if ($rcvLocations->count() > 1) throw new \Exception('Multiple "Receiving" locations (Zone: RCV) found. Please set one as "Default Receiving".');
                                     else throw new \Exception('No active "Receiving" location (Zone: RCV) found in the source warehouse.');
                                }
                                $outboundMovements = InventoryMovement::where('reference_type', Shipment::class)
                                    ->where('reference_id', $record->id)
                                    ->where('quantity_change', '<', 0)->get();
                                foreach ($outboundMovements as $movement) {
                                    $originalInventory = $movement->inventory;
                                    if (!$originalInventory) {
                                        Log::warning("Skipping stock return for movement ID {$movement->id}: Original inventory (at Staging) not found.");
                                        continue;
                                    }
                                    $originalInventory->loadMissing('product');
                                    $quantityToReturn = abs($movement->quantity_change);
                                    $rcvInventory = Inventory::firstOrCreate(
                                        [
                                            'location_id' => $cancelLocation->id,
                                            'product_id' => $originalInventory->product_id,
                                            'batch' => $originalInventory->batch,
                                        ],
                                        ['sled' => $originalInventory->sled, 'avail_stock' => 0, 'business_id' => $record->business_id]
                                    );
                                    $rcvInventory->increment('avail_stock', $quantityToReturn);
                                    InventoryMovement::create([
                                        'inventory_id' => $rcvInventory->id,
                                        'quantity_change' => $quantityToReturn,
                                        'stock_after_move' => $rcvInventory->avail_stock,
                                        'type' => 'RETURN_CANCEL',
                                        'reference_type' => Shipment::class,
                                        'reference_id' => $record->id,
                                        'user_id' => Auth::id(),
                                        'notes' => 'Stock returned to Receiving (Zone: RCV) from cancelled DO #' . $record->shipment_number,
                                    ]);
                                    $putAwayItems->push([
                                        'product_id' => $originalInventory->product_id,
                                        'quantity' => $quantityToReturn,
                                        'uom' => $originalInventory->product?->base_uom ?? 'PCS',
                                    ]);
                                }
                            }
                            $record->update(['status' => 'cancelled']);
                            $record->fleets()->update(['status' => 'available']);
                            if (method_exists($record->fleets(), 'syncWithPivotValues')) {
                                $record->fleets()->syncWithPivotValues($record->fleets->pluck('id')->all(), ['status' => 'cancelled']);
                            }
                            if ($record->pickingLists->isNotEmpty()) {
                                $record->pickingLists()->update(['status' => 'completed']);
                                $record->pickingLists()->detach();
                                Log::info("Reverted and Detached " . $record->pickingLists->count() . " Picking Lists.");
                            }
                            if ($record->sourceables->isNotEmpty()) {
                                $newSourceStatus = 'ready_to_ship';
                                if ($record->salesOrders->isNotEmpty()) {
                                    $record->salesOrders()->update(['status' => $newSourceStatus]);
                                    $record->salesOrders()->detach();
                                }
                                if ($record->stockTransfers->isNotEmpty()) {
                                    $record->stockTransfers()->update(['status' => $newSourceStatus]);
                                    $record->stockTransfers()->detach();
                                }
                                Log::info("Reverted and Detached " . $record->sourceables->count() . " Sourceables (SO/STO).");
                            }
                            if ($putAwayItems->isNotEmpty() && isset($cancelLocation)) {
                                $groupedItems = $putAwayItems->groupBy('product_id')->map(function ($group) {
                                    return [
                                        'product_id' => $group->first()['product_id'],
                                        'quantity' => $group->sum('quantity'),
                                        'uom' => $group->first()['uom'],
                                    ];
                                })->values();
                                Log::info("Creating Put-Away Task for cancelled shipment stock...");
                                $putAwayTask = StockTransfer::create([
                                    'transfer_number' => 'PA-CANCEL-' . $record->shipment_number,
                                    'business_id' => $record->business_id,
                                    'source_location_id' => $cancelLocation->id,
                                    'destination_location_id' => null,
                                    'status' => 'draft',
                                    'notes' => 'Tugas put-away otomatis dari DO Batal #' . $record->shipment_number,
                                    'requested_by_user_id' => Auth::id(),
                                    'request_date' => now(),
                                    'sourceable_type' => Shipment::class,
                                    'sourceable_id' => $record->id,
                                    'plant_id' => $cancelLocation->locatable?->plant_id,
                                    'transfer_type' => 'put_away',
                                ]);
                                $putAwayTask->items()->createMany($groupedItems->toArray());
                                Log::info("Put-Away Task {$putAwayTask->transfer_number} created with {$groupedItems->count()} items.");
                            }
                        });
                        Notification::make()->title('Shipment Has Been Cancelled')->body('Stock (if any) has been returned. Source documents are now unlinked.')->warning()->send();
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Log::error("CancelShipment Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()->title('Cancellation Failed')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),


            //Actions\DeleteAction::make(),Put-Away Task
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

}
