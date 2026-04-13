<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\ProductionOrder;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditProductionOrder extends EditRecord
{
    protected static string $resource = ProductionOrderResource::class;
    use HasPermissionChecks;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
            ->label('Print')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (ProductionOrder $record): string => route('production-orders.print', $record))
            ->openUrlInNewTab(),



            /**
             * AKSI 1: CHECK KETERSEDIAAN BAHAN BAKU
             */
            Actions\Action::make('approveProduction')
                ->label('Approve & Check Materials')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation() // Tambahkan konfirmasi
                ->visible(fn (ProductionOrder $record) => // Gunakan $record
                    $record->status === 'draft' &&
                    // PERBAIKAN: Gunakan $this->check (asumsi Trait ada di EditProductionOrder)
                    $this->check(Auth::user(), 'create production orders') // Sesuaikan nama permission jika perlu
                )
                ->form([
                    Select::make('warehouse_id') // Nama field tetap
                        ->label('Source Warehouse (for Materials)')
                        ->options(function (ProductionOrder $record): array {
                            // Ambil Plant ID dari Production Order
                            $plantId = $record->plant_id;
                            if (!$plantId) return [];

                            // Ambil tipe gudang yang relevan untuk bahan baku
                            $relevantWarehouseTypes = ['RAW_MATERIAL', 'COLD_STORAGE', 'GENERAL', 'MAIN'];

                            // Tampilkan gudang di Plant tsb yang tipenya relevan & statusnya aktif
                            return Warehouse::where('plant_id', $plantId)
                                ->whereIn('type', $relevantWarehouseTypes)
                                ->where('status', true)
                                ->pluck('name', 'id')
                                ->toArray();
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->helperText('Pilih gudang tempat bahan baku akan diambil.'),
                ])
                ->action(function (ProductionOrder $record, array $data) {
                    $warehouseId = $data['warehouse_id'];

                    try {
                        // ==========================================================
                        // --- MULAI DB TRANSACTION ---
                        // ==========================================================
                        DB::transaction(function () use ($record, $warehouseId) {
                            // Eager load relasi yang dibutuhkan
                            // Gunakan relasi bom() dan items() yang baru
                            $record->loadMissing('finishedGood.bom.items.product.uoms');

                            // 1. Ambil BOM dari Produk Jadi
                            $bom = $record->finishedGood?->bom;
                            if (!$bom || $bom->items->isEmpty()) {
                                throw new \Exception("No valid BOM (Bill of Materials) found for '{$record->finishedGood->name}'.");
                            }

                            // 2. Tentukan Zona Prioritas untuk Bahan Baku
                            $zones = Zone::pluck('id', 'code')->all();
                            $zonePriorityMap = [
                                'RAW_MATERIAL' => ['RM', 'COLD', 'GEN'], // Prioritas Zona
                                // Tambahkan map lain jika perlu
                            ];
                            $defaultPriority = ['GEN'];

                            // 3. Ambil *semua* Lokasi Sellable/Aktif di Warehouse terpilih
                            $allSellableLocationIds = Location::where('locatable_type', Warehouse::class)
                                    ->where('locatable_id', $warehouseId)
                                    ->where('is_sellable', true)
                                    ->where('status', true)
                                    ->pluck('id')->toArray();

                            if (empty($allSellableLocationIds)) {
                                throw new \Exception('No active sellable locations found in the selected warehouse.');
                            }

                            // 4. Loop setiap item di BOM
                            foreach ($bom->items as $bomItem) {

                                // ==========================================================
                                // --- GUNAKAN KONSEP 'usage_type' ---
                                // ==========================================================
                                // Hanya cek stok untuk 'RAW_MATERIAL' (konsumsi pabrik)
                                if ($bomItem->usage_type !== 'RAW_MATERIAL') {
                                    continue;
                                }
                                // ==========================================================

                                // 5. Hitung Kebutuhan Total (Base UoM)
                                $product = $bomItem->product;
                                if (!$product) continue; // Lewati jika produk tidak ada

                                $uom = $product->uoms->where('uom_name', $bomItem->uom)->first();
                                $conversionRate = $uom?->conversion_rate ?? 1;
                                $qtyPerUnitBase = (float)$bomItem->quantity * $conversionRate;
                                $totalRequiredInBaseUom = $qtyPerUnitBase * (float)$record->quantity_planned;

                                if ($totalRequiredInBaseUom <= 0) continue; // Lewati jika qty 0

                                // 6. Tentukan Prioritas Zona Spesifik Item
                                $storageCondition = $product->storage_condition; // Asumsi ada field ini
                                if ($storageCondition === 'COLD' && isset($zones['COLD'])) {
                                    $priorityCodes = ['COLD', 'GEN'];
                                } else {
                                    // Ambil prioritas berdasarkan product_type RM
                                    $priorityCodes = $zonePriorityMap['RAW_MATERIAL'] ?? $defaultPriority;
                                }
                                $zonePriorityOrder = collect($priorityCodes)->map(fn($c) => $zones[$c] ?? null)->filter()->unique()->all();

                                // 7. Cek Stok (Zone-Aware)
                                $inventoryQueryBase = Inventory::whereIn('location_id', $allSellableLocationIds)
                                    ->where('product_id', $product->id)->where('avail_stock', '>', 0);

                                $totalAvailableStock = 0;
                                // Loop per Zona Prioritas
                                foreach ($zonePriorityOrder as $zoneId) {
                                     $stockInZone = (clone $inventoryQueryBase)
                                                    ->whereHas('location', fn($q) => $q->where('zone_id', $zoneId))
                                                    ->sum('avail_stock');
                                     $totalAvailableStock += $stockInZone;
                                }

                                Log::info("Stock Check for '{$product->name}': Required: {$totalRequiredInBaseUom}, Available: {$totalAvailableStock} (in relevant zones)");

                                // 8. Bandingkan
                                if ($totalAvailableStock < $totalRequiredInBaseUom) {
                                    // ==========================================================
                                    // --- BAGIAN YANG HILANG (LENGKAP) ---
                                    // ==========================================================
                                    throw new \Exception("Insufficient stock for '{$product->name}'. Required: {$totalRequiredInBaseUom}, Available: {$totalAvailableStock}");
                                }
                            } // Akhir loop foreach

                            // ==========================================================
                            // LANGKAH 9: JIKA SEMUA SUKSES, UPDATE STATUS
                            // ==========================================================
                            $record->update([
                                'status' => 'approved', // Ganti status
                                'warehouse_id' => $warehouseId // Simpan gudang sumber
                            ]);

                        });
                        Notification::make()->title('Production Order Approved!')
                            ->body('All materials are available in the selected warehouse.')
                            ->success()->send();

                        // Refresh form untuk update status
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                        // $this->getRecord()->refresh();
                        // $this->refreshFormData($this->getRecord()->toArray());

                    } catch (\Exception $e) {
                        // ==========================================================
                        // --- CATCH BLOCK (LENGKAP) ---
                        // ==========================================================
                        // Jika validasi stok gagal (throw new Exception), update status
                        // Periksa apakah status masih 'draft' sebelum update (jika ada error lain)
                        if ($record->status === 'draft') {
                             $record->update(['status' => 'insufficient_materials']);
                        }

                        Notification::make()->title('Stock Check Failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $this->halt(); // Hentikan action
                    }
                }),

                Actions\Action::make('generateMaterialPickingList')
                ->label('Generate Material Picking List')
                ->icon('heroicon-o-list-bullet')
                ->color('info')
                ->requiresConfirmation()
                // Muncul HANYA jika status 'approved'
                ->visible(fn (ProductionOrder $record) =>
                    $record->status === 'approved' &&
                    // Gunakan ->check() (asumsi Trait HasPermissionChecks ada)
                    $this->check(Auth::user(), 'create picking list') // Ganti permission jika perlu
                )
                ->form([
                    // Form ini HANYA meminta PIC
                    // Warehouse sudah ditentukan saat 'approveProduction'
                    Select::make('assigned_user_id')
                        ->label('Assign Picking Task To')
                        ->options(fn (ProductionOrder $record) =>
                            // Opsi user di Plant/Warehouse yang sama
                            User::where('business_id', $record->business_id)
                                ->where('status', true)
                                // TODO: Filter user berdasarkan Warehouse/Plant
                                ->whereHas('position', fn ($q) => $q->whereIn('name', ['Staff Gudang', 'Manager Gudang']))
                                ->pluck('name', 'id')
                        )
                        ->searchable()->required(),
                ])
                ->action(function (ProductionOrder $record, array $data) {
                    try {
                        DB::transaction(function () use ($record, $data) {
                            // 1. Validasi Awal
                            if ($record->pickingList()->where('status', '!=', 'cancelled')->exists()) {
                                throw ValidationException::withMessages(['error' => 'An active picking list already exists.']);
                            }

                            // Muat relasi BOM yang baru
                            // $record->loadMissing('finishedGood.bom.items.product.uoms', 'warehouse');
                            $record->loadMissing('finishedGood.bom.items.product.uoms');
                            $bom = $record->finishedGood?->bom;
                            if (!$bom || $bom->items->isEmpty()) {
                                throw new \Exception("No valid BOM found for '{$record->finishedGood->name}'.");
                            }

                            // 2. Tentukan Lokasi Sumber & Zona
                            $sourceWarehouseId = $record->warehouse_id; // Ambil dari PO (hasil approve)
                            if (!$sourceWarehouseId) {
                                 throw new \Exception('Source Warehouse ID is not set on this Production Order.');
                            }

                            $zones = Zone::pluck('id', 'code')->all();
                            $generalZoneId = $zones['GEN'] ?? null;

                            // Ambil lokasi sellable, aktif, dan OWNED
                            $sellableLocationIds = Location::where('locatable_type', Warehouse::class)
                                    ->where('locatable_id', $sourceWarehouseId)
                                    ->where('is_sellable', true)
                                    ->where('status', true)
                                    // ==========================================================
                                    // --- PENTING: Produksi hanya boleh ambil stok OWNED ---
                                    // ==========================================================
                                    ->where('ownership_type', 'owned')
                                    ->pluck('id')->toArray();

                            if (empty($sellableLocationIds)) {
                                throw ValidationException::withMessages(['error' => 'No active/sellable/owned locations found in the source warehouse.']);
                            }

                            // 3. Buat Picking List
                            $pickingList = $record->pickingList()->create([
                                'picking_list_number' => 'PL-PROD-' . $record->production_order_number, // Awalan baru
                                'user_id' => $data['assigned_user_id'],
                                'status' => 'pending',
                                'warehouse_id' => $sourceWarehouseId,
                                'business_id' => $record->business_id,
                            ]);

                            // 4. Loop item BOM untuk alokasi
                            foreach ($bom->items as $bomItem) {

                                // Hanya alokasikan stok untuk 'RAW_MATERIAL' (sesuai skenario)
                                if ($bomItem->usage_type !== 'RAW_MATERIAL') {
                                    continue;
                                }

                                $product = $bomItem->product;
                                if (!$product) continue;
                                $product->loadMissing('uoms');

                                // Hitung total kebutuhan (Base UoM)
                                $uom = $product->uoms->where('uom_name', $bomItem->uom)->first();
                                $conversionRate = $uom?->conversion_rate ?? 1;
                                $qtyPerUnitBase = (float)$bomItem->quantity * $conversionRate;
                                $totalQtyToPick = $qtyPerUnitBase * (float)$record->quantity_planned;
                                if ($totalQtyToPick <= 0) continue;

                                $pickingListItem = $pickingList->items()->create([
                                    'product_id' => $product->id,
                                    'total_quantity_to_pick' => $totalQtyToPick,
                                    'uom' => $product->base_uom
                                ]);

                                // Tentukan Prioritas Zona
                                $storageCondition = $product->storage_condition;
                                $priorityCodes = ['RM', 'GEN']; // Default RM
                                if ($storageCondition === 'COLD' && isset($zones['COLD'])) {
                                    $priorityCodes = ['COLD', 'GEN'];
                                }
                                $zonePriorityOrder = collect($priorityCodes)->map(fn($c) => $zones[$c] ?? null)->filter()->unique()->all();

                                // Query Inventory (Hanya OWNED, sudah difilter di $sellableLocationIds)
                                $inventoryQueryBase = Inventory::whereIn('location_id', $sellableLocationIds)
                                    ->where('product_id', $product->id)->where('avail_stock', '>', 0);

                                // (Logika Minimum SLED TIDAK DIPERLUKAN untuk produksi internal)

                                $allocatedQty = 0;
                                $remainingToPick = $totalQtyToPick;

                                // Loop Prioritas Zona (FEFO-in-Zone)
                                foreach ($zonePriorityOrder as $zoneId) {
                                    if ($remainingToPick <= 0) break;

                                    $inventoriesInZone = (clone $inventoryQueryBase)
                                                    ->whereHas('location', fn($q) => $q->where('zone_id', $zoneId))
                                                    ->orderBy('sled', 'asc')->get(); // FEFO

                                    foreach ($inventoriesInZone as $inventory) {
                                        if ($remainingToPick <= 0) break;
                                        $qtyFromThisBatch = min($remainingToPick, $inventory->avail_stock);
                                        $pickingListItem->sources()->create([
                                            'inventory_id' => $inventory->id,
                                            'quantity_to_pick_from_source' => $qtyFromThisBatch
                                        ]);
                                        $remainingToPick -= $qtyFromThisBatch;
                                        $allocatedQty += $qtyFromThisBatch;
                                    }
                                }

                                // Cek akhir
                                if (round($allocatedQty, 5) < round($totalQtyToPick, 5)) {
                                     throw ValidationException::withMessages(['error' => "Insufficient 'owned' stock for '{$product->name}' across RM/COLD/GEN zones."]);
                                }
                            } // Akhir loop item

                            // 5. Update status PO menjadi 'pending_picking'
                            $record->update(['status' => 'pending_picking']);
                        }); // Akhir DB Transaction

                        Notification::make()->title('Material Picking List generated successfully!')->success()->send();

                        // Refresh form
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                        // $this->getRecord()->refresh();
                        // $this->refreshFormData($this->getRecord()->toArray());

                    } catch (ValidationException $e) {
                        Notification::make()->title('Failed to generate picking list')->body($e->getMessage())->danger()->send();
                    } catch (\Exception $e) {
                         Log::error("GenerateMaterialPickingList Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                         Notification::make()->title('Error')->body('An unexpected error occurred: '.$e->getMessage())->danger()->send();
                    }
                }),

            // AKSI 2: COMPLETE PRODUCTION
            Actions\Action::make('completeProduction')
                ->label('Complete Production')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn (ProductionOrder $record) =>
                    // Status 'ready_to_produce' di-set oleh 'completePick'
                    $record->status === 'ready_to_produce' &&
                    $this->check(Auth::user(), 'complete production orders')
                )
                // ==========================================================
                // --- FORM DENGAN UOM ---
                // ==========================================================
                ->form([
                    TextInput::make('quantity_produced')
                        ->numeric()->required()
                        ->label('Quantity Produced (Success)')
                        ->helperText('Jumlah produk jadi yang berhasil diproduksi.')
                        ->default(fn (ProductionOrder $record) => $record->quantity_planned)
                        ->columnSpan(1),

                    Select::make('produced_uom')
                        ->label('UoM')
                        ->options(function (ProductionOrder $record): array {
                            if (!$record->finishedGood) return [];
                            return $record->finishedGood->uoms()->pluck('uom_name', 'uom_name');
                        })
                        ->default(function (ProductionOrder $record) {
                            if (!$record->finishedGood) return null;
                            return $record->finishedGood->base_uom; // Default ke Base UoM
                        })
                        ->required()
                        ->columnSpan(1),

                    TextInput::make('quantity_failed')
                        ->numeric()->required()->default(0)
                        ->label('Quantity Failed (Scrap)')
                        ->minValue(0)
                        ->columnSpan(1),

                    Select::make('failed_uom')
                        ->label('UoM')
                        ->options(function (ProductionOrder $record): array {
                            if (!$record->finishedGood) return [];
                            return $record->finishedGood->uoms()->pluck('uom_name', 'uom_name');
                        })
                        ->default(function (ProductionOrder $record) {
                            if (!$record->finishedGood) return null;
                            return $record->finishedGood->base_uom; // Default ke Base UoM
                        })
                        ->required()
                        ->columnSpan(1),

                    TextInput::make('batch')
                        ->label('Batch Number for Finished Good')
                        ->default('PROD-' . now()->format('YmdHis'))
                        ->required()
                        ->columnSpanFull(),
                ])
                // ==========================================================
                // --- ACTION LOGIC DENGAN UOM ---
                // ==========================================================
                ->action(function (ProductionOrder $record, array $data) {
                    try {
                        DB::transaction(function () use ($record, $data) {
                            // Eager load relasi yang dibutuhkan
                            $record->loadMissing('finishedGood.bom.items.product.uoms', 'finishedGood.uoms');

                            $finishedGood = $record->finishedGood;
                            if (!$finishedGood) {
                                throw new \Exception("Finished Good product data is missing.");
                            }

                            // --- MULAI LOGIKA KONVERSI UOM ---
                            // 1. Konversi Qty Produced
                            $uomProduced = $finishedGood->uoms->where('uom_name', $data['produced_uom'])->first();
                            $rateProduced = $uomProduced?->conversion_rate ?? 1;
                            $qtyProducedBase = (float)$data['quantity_produced'] * $rateProduced;

                            // 2. Konversi Qty Failed
                            $uomFailed = $finishedGood->uoms->where('uom_name', $data['failed_uom'])->first();
                            $rateFailed = $uomFailed?->conversion_rate ?? 1;
                            $qtyFailedBase = (float)$data['quantity_failed'] * $rateFailed;

                            // 3. Hitung Total Konsumsi (dalam Base UoM)
                            $totalConsumedBase = $qtyProducedBase + $qtyFailedBase;
                            // --- AKHIR LOGIKA KONVERSI UOM ---

                            if ($totalConsumedBase <= 0) {
                                throw new \Exception('Total produced + failed quantity must be greater than zero.');
                            }

                            $bom = $record->finishedGood?->bom;
                            if (!$bom || $bom->items->isEmpty()) {
                                throw new \Exception("No valid BOM found for '{$record->finishedGood->name}'.");
                            }

                            $warehouseId = $record->warehouse_id;
                            if (!$warehouseId) {
                                 throw new \Exception('Source Warehouse ID is not set on this Production Order.');
                            }

                            // 1. Tentukan Lokasi Staging Produksi (SUMBER RM)
                            $prodStagingZone = Zone::whereIn('code', ['RM', 'LINE-A', 'PROD-STG'])->first();
                            if (!$prodStagingZone) throw new \Exception("No 'RM', 'LINE-A' or 'PROD-STG' Zone found for production staging.");
                            $sourceLocation = Location::where('locatable_type', Warehouse::class)
                                ->where('locatable_id', $warehouseId)
                                ->where('zone_id', $prodStagingZone->id)
                                ->where('status', true)
                                ->first();
                            if (!$sourceLocation) throw new \Exception("No active Location found in Zone '{$prodStagingZone->code}' for Warehouse ID {$warehouseId}.");
                            $sourceLocationId = $sourceLocation->id;

                            // 2. Tentukan Lokasi Output (TUJUAN FG)
                            $outputZone = Zone::where('code', 'QI')->first();
                            if (!$outputZone) throw new \Exception("No 'QI' Zone found for production output.");
                            $destinationLocation = Location::where('locatable_type', Warehouse::class)
                                ->where('locatable_id', $warehouseId)
                                ->where('zone_id', $outputZone->id)
                                ->where('status', true)
                                ->first();
                            if (!$destinationLocation) throw new \Exception("No active Location found in Zone 'QI' for Warehouse ID {$warehouseId}.");


                            // ==========================================================
                            // LANGKAH 1: KONSUMSI RM DARI LOKASI STAGING
                            // ==========================================================
                            foreach ($bom->items as $bomItem) {
                                if ($bomItem->usage_type !== 'RAW_MATERIAL') continue;
                                $product = $bomItem->product;
                                if (!$product) continue;
                                $product->loadMissing('uoms');

                                $uom = $product->uoms->where('uom_name', $bomItem->uom)->first();
                                $conversionRate = $uom?->conversion_rate ?? 1;
                                $qtyPerUnitBase = (float)$bomItem->quantity * $conversionRate;

                                // === MENGGUNAKAN $totalConsumedBase ===
                                $totalRequiredInBaseUom = $qtyPerUnitBase * $totalConsumedBase;

                                if ($totalRequiredInBaseUom <= 0) continue;

                                $inventoryQueryBase = Inventory::where('location_id', $sourceLocationId)
                                    ->where('product_id', $product->id)->where('avail_stock', '>', 0);

                                $totalAvailableStock = $inventoryQueryBase->sum('avail_stock');
                                if ($totalAvailableStock < $totalRequiredInBaseUom) {
                                    throw new \Exception("Insufficient stock for '{$product->name}' in Production Staging Area ({$sourceLocation->name}). Required: {$totalRequiredInBaseUom}, Available: {$totalAvailableStock}");
                                }

                                $remainingToConsume = $totalRequiredInBaseUom;
                                $inventories = (clone $inventoryQueryBase)->orderBy('sled', 'asc')->get();

                                foreach ($inventories as $inventory) {
                                    if ($remainingToConsume <= 0) break;
                                    $qtyFromThisBatch = min($remainingToConsume, $inventory->avail_stock);
                                    $inventory->decrement('avail_stock', $qtyFromThisBatch);
                                    InventoryMovement::create([
                                        'inventory_id' => $inventory->id, 'quantity_change' => -$qtyFromThisBatch,
                                        'stock_after_move' => $inventory->avail_stock, 'type' => 'PRODUCTION_OUT',
                                        'reference_type' => ProductionOrder::class, 'reference_id' => $record->id,
                                        'user_id' => Auth::id(),
                                        'notes' => "Consumed from {$sourceLocation->name} for PO #{$record->production_order_number}",
                                    ]);
                                    $remainingToConsume -= $qtyFromThisBatch;
                                }
                            }

                            // ==========================================================
                            // LANGKAH 2: PENAMBAHAN STOK PRODUK JADI (YIELD) ke Zona QI
                            // ==========================================================
                            if ($qtyProducedBase > 0) { // === MENGGUNAKAN $qtyProducedBase ===
                                $finishedGoodInventory = Inventory::firstOrCreate(
                                    [
                                        'location_id' => $destinationLocation->id,
                                        'product_id' => $record->finished_good_id,
                                        'batch' => $data['batch'],
                                        'type' => 'quality_inspection', // Tipe stok = QI
                                    ],
                                    ['sled' => now()->addYear(), 'avail_stock' => 0, 'business_id' => $record->business_id]
                                );
                                $finishedGoodInventory->increment('avail_stock', $qtyProducedBase); // === MENGGUNAKAN $qtyProducedBase ===
                                InventoryMovement::create([
                                    'inventory_id' => $finishedGoodInventory->id,
                                    'quantity_change' => $qtyProducedBase, // === MENGGUNAKAN $qtyProducedBase ===
                                    'stock_after_move' => $finishedGoodInventory->avail_stock,
                                    'type' => 'PRODUCTION_IN',
                                    'reference_type' => ProductionOrder::class, 'reference_id' => $record->id,
                                    'user_id' => Auth::id(),
                                    'notes' => "Yield from PO #{$record->production_order_number} to {$destinationLocation->name} (QI)",
                                ]);
                            }

                            // ==========================================================
                            // LANGKAH 3: BUAT PUT-AWAY TASK UNTUK PRODUK JADI
                            // ==========================================================
                            if ($qtyProducedBase > 0) { // === MENGGUNAKAN $qtyProducedBase ===
                                $putAwayTask = StockTransfer::create([
                                    'transfer_number' => 'PA-PROD-' . $record->production_order_number,
                                    'business_id' => $record->business_id,
                                    'plant_id' => $record->plant_id,
                                    'source_location_id' => $destinationLocation->id,
                                    'destination_location_id' => null,
                                    'status' => 'draft',
                                    'notes' => 'Tugas put-away otomatis dari PO #' . $record->production_order_number,
                                    'requested_by_user_id' => Auth::id(),
                                    'request_date' => now(),
                                    'sourceable_type' => ProductionOrder::class,
                                    'sourceable_id' => $record->id,
                                ]);

                                $putAwayTask->items()->create([
                                    'product_id' => $record->finished_good_id,
                                    'quantity' => $qtyProducedBase, // === MENGGUNAKAN $qtyProducedBase ===
                                    'uom' => $record->finishedGood->base_uom,
                                ]);
                                Log::info("Created Put-Away Task {$putAwayTask->transfer_number} for completed production.");
                            }

                            // ==========================================================
                            // LANGKAH 4: UPDATE STATUS PRODUCTION ORDER
                            // ==========================================================
                            $record->update([
                                'status' => 'completed', 'completed_at' => now(), 'completed_by_user_id' => Auth::id(),
                                'quantity_produced' => $qtyProducedBase, // <-- Simpan Base UoM
                                'quantity_failed' => $qtyFailedBase, // <-- Simpan Base UoM
                            ]);
                        }); // --- AKHIR DB TRANSACTION ---

                        Notification::make()->title('Production completed successfully!')->body('Finished goods moved to output area (QI) and Put-Away Task created.')->success()->send();

                        // Refresh form
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                        // $this->getRecord()->refresh();
                        // $this->refreshFormData($this->getRecord()->toArray());

                    } catch (\Exception $e) {
                        Log::error("CompleteProduction Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()->title('Failed to complete production')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

            //Actions\DeleteAction::make(),
        ];
    }

}
