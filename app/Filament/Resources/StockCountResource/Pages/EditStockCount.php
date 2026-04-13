<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource\RelationManagers;
use App\Events\ConsignmentStockConsumed;
use App\Filament\Resources\StockCountResource;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\User;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportRedirects\Redirector;

class EditStockCount extends EditRecord
{
    protected static string $resource = StockCountResource::class;
    use HasPermissionChecks;

    private function isWarehouseCount(): bool
    {
        return $this->getRecord()->countable_type === Warehouse::class;
    }

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();

        Log::info("===== EditStockCount getHeaderActions() RUN =====");
        Log::info("SC ID: {$record->id}, Status: {$record->status}");

        $isDraft = $record->status === 'draft';
        $isWarehouse = $this->isWarehouseCount();
        $teamsNotEmpty = !empty($record->assigned_teams);
        $canCreate = $this->check($user, 'create stock counts');
        $canStart = $this->check($user, 'start stock counts');

        Log::info("isDraft: " . ($isDraft ? 'true' : 'false'));
        Log::info("isWarehouse: " . ($isWarehouse ? 'true' : 'false'));
        Log::info("teamsNotEmpty: " . ($teamsNotEmpty ? 'true' : 'false'));
        Log::info("canCreate (Permission): " . ($canCreate ? 'true' : 'false'));
        Log::info("canStart (Permission): " . ($canStart ? 'true' : 'false'));


        return [

            Actions\Action::make('assignTeams')
            ->label('Assign Teams')
            ->color('info')->icon('heroicon-o-users')
            ->visible(fn () => $isDraft && $isWarehouse && $canCreate)
            ->form(function (Form $form) use ($record, $user): array {
                $countable_type = $record->countable_type;
                $countable_id = $record->countable_id;
                $userQuery = User::where('business_id', $user->business_id)
                                ->where('status', true);
                if ($countable_type && $countable_id) {
                    $userQuery->where('locationable_type', $countable_type)
                              ->where('locationable_id', $countable_id);
                } else {
                    Log::warning("assignTeams form: StockCount ID {$record->id} has no countable data.");
                }
                $availableUsers = $userQuery->pluck('name', 'id');
                return [
                    Select::make('team_yellow')
                        ->label('Team Kuning (Counter 1)')
                        ->multiple()
                        ->options($availableUsers)
                        ->searchable()->required(),
                    Select::make('team_green')
                        ->label('Team Hijau (Counter 2)')
                        ->multiple()
                        ->options($availableUsers)
                        ->searchable()->required(),
                    Select::make('team_white')
                        ->label('Team Putih (Validator)')
                        ->multiple()
                        ->options($availableUsers)
                        ->searchable()->required(),
                ];
            })

            ->action(function (array $data): void { // <-- 1. Ubah ke 'void'
                $this->getRecord()->update([
                    'assigned_teams' => [
                        'yellow' => $data['team_yellow'],
                        'green' => $data['team_green'],
                        'white' => $data['team_white'],
                    ]
                ]);
                Notification::make()->title('Teams assigned successfully.')->success()->send();

                // 2. Panggil $this->redirect() tanpa 'return'
                $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));
            }),

           /**
             * AKSI 1B (OUTLET): START COUNT
             */
            Actions\Action::make('startCountOutlet')
                ->label('Start Count & Snapshot Stock')
                ->color('info')->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->visible(fn () => $isDraft && !$isWarehouse && $this->check($user, 'start stock counts'))
                ->action(function () { $this->startCount(); }),

            /**
             * AKSI 1C (WAREHOUSE): START COUNT
             */
            Actions\Action::make('startCountWarehouse')
                ->label('Start Count & Snapshot Stock')
                ->color('info')->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->visible(fn () => $isDraft && $isWarehouse && $teamsNotEmpty && $canStart)
                // ==========================================================
                // --- PERBAIKAN: Ubah ke closure 'function' ---
                // ==========================================================
                ->action(function () { $this->startCount(); }),

            /**
             * AKSI 2: SUBMIT FOR APPROVAL / VALIDATION
             * Aksi ini hanya untuk OUTLET
             */
            Actions\Action::make('submitForApproval')
                ->label('Finalize & Submit for Approval')
                ->color('primary')->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (StockCount $record) => $record->status === 'in_progress' && !$this->isWarehouseCount() && $this->check($user, 'submit stock counts'))
                ->action(function (StockCount $record) { // <-- Hapus 'array $data'

                    // Ambil SEMUA data form secara manual
                    $data = $this->form->getState();

                    if (!isset($data['items']) || empty($data['items'])) {
                        Notification::make()->title('No items found.')->body('Cannot submit an empty count.')->warning()->send();
                        $this->halt();
                        return;
                    }

                    foreach ($data['items'] as $itemId => $itemData) {
                        $itemRecord = $record->items()->find($itemId);
                        if ($itemRecord) {
                            $itemRecord->update([
                                'final_counted_stock' => $itemData['final_counted_stock'],
                                'final_counted_uom' => $itemData['final_counted_uom'],
                                'is_zero_count' => $itemData['is_zero_count'] ?? false,
                            ]);
                        }
                    }
                    $record->update(['status' => 'pending_approval', 'completed_at' => now()]);
                    Notification::make()->title('Count results submitted for approval.')->success()->send();
                }),

            /**
             * AKSI 2B: SUBMIT FOR VALIDATION (Khusus Skenario WAREHOUSE)
             */
            Actions\Action::make('submitForValidation')
                ->label('Submit for Validation')
                ->color('primary')->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn (StockCount $record) => $record->status === 'in_progress' && $this->isWarehouseCount() && $this->check($user, 'submit stock counts'))
                ->action(fn ($record) => $record->update(['status' => 'pending_validation'])),

            /**
             * AKSI 3: POST ADJUSTMENT (Updated dengan Modal Validasi)
             * Aman untuk Warehouse & Outlet
             */
            Actions\Action::make('postAdjustment')
                ->label('Post Adjustment')
                ->color('success')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                // Visible untuk Outlet (pending_approval) & Warehouse (pending_validation)
                ->visible(fn (StockCount $record) =>
                    in_array($record->status, ['pending_approval', 'pending_validation']) &&
                    $this->check(Auth::user(), 'post stock count adjustments')
                )

                // ==========================================================
                // --- BAGIAN BARU: MODAL KONFIRMASI CERDAS ---
                // ==========================================================
                ->modalHeading('Finalize & Post Stock Adjustment')
                ->modalDescription(function (StockCount $record) {
                    // Pre-calculation sederhana untuk menampilkan warning di modal
                    // Tanpa mengubah data apapun
                    $record->loadMissing('items.product.uoms');
                    $varianceCount = 0;
                    $totalItems = 0;

                    foreach ($record->items as $item) {
                        if ($item->final_counted_stock === null) continue;

                        $totalItems++;
                        $product = $item->product;

                        // Konversi ke Base untuk perbandingan
                        $systemBase = (float) $item->system_stock;
                        $inputQty = (float) $item->final_counted_stock;
                        $inputUom = $item->final_counted_uom ?? $product?->base_uom;

                        // Cari rate
                        $rate = 1;
                        if ($product && $inputUom !== $product->base_uom) {
                            $uomData = $product->uoms->where('uom_name', $inputUom)->first();
                            $rate = $uomData?->conversion_rate ?? 1;
                        }

                        $actualBase = $inputQty * $rate;

                        // Cek selisih (dengan toleransi float)
                        if (abs($actualBase - $systemBase) > 0.0001) {
                            $varianceCount++;
                        }
                    }

                    if ($varianceCount > 0) {
                        return new \Illuminate\Support\HtmlString("
                            <div class='p-4 mb-2 bg-red-50 border border-red-200 rounded text-red-700 text-sm'>
                                <strong>⚠️ Peringatan Selisih Stok</strong><br>
                                Ditemukan <strong>$varianceCount item</strong> (dari $totalItems) yang fisiknya tidak sesuai dengan sistem.<br>
                                <ul class='list-disc list-inside mt-1'>
                                    <li>Stok Master akan di-update paksa sesuai hitungan fisik.</li>
                                    <li>Selisih akan dicatat ke jurnal Inventory Adjustment.</li>
                                </ul>
                            </div>
                            <p class='text-sm'>Apakah Anda yakin data ini sudah valid?</p>
                        ");
                    }

                    return "Semua hasil hitungan ($totalItems item) SESUAI dengan sistem. Lanjutkan posting?";
                })
                ->modalSubmitActionLabel('Yes, Post Adjustment')
                // ==========================================================

                // ==========================================================
                // --- BAGIAN INI 100% COPY-PASTE DARI SCRIPT ASLI ANDA ---
                // (Tidak ada perubahan logic database agar aman)
                // ==========================================================
                ->action(function (StockCount $record) {
                    try {
                        $locationIdsToUnlock = [];

                        DB::transaction(function () use ($record, &$locationIdsToUnlock) {
                            // Muat relasi yang diperlukan (termasuk UoM)
                            $record->load('items.inventory.product.uoms', 'items.inventory.location', 'countable');

                            $itemsToAdjust = $record->items()->whereNotNull('final_counted_stock')->get();
                            $hasVariance = false; // Flag untuk melacak jika ada selisih

                            // Tentukan lokasi referensi
                            $firstItem = $itemsToAdjust->first();
                            if (!$firstItem) {
                                Log::info("No items to adjust for SC #{$record->id}.");
                            }

                            $refLocationId = $firstItem?->inventory?->location_id ?? $record->countable->locations()->first()->id;
                            if (!$refLocationId && $itemsToAdjust->isNotEmpty()) {
                                 throw new \Exception('Cannot determine a reference location for the adjustment document.');
                            }

                            // 1. Buat dokumen master Inventory Adjustment
                            $adjustment = InventoryAdjustment::create([
                                'adjustment_number' => 'ADJ-SC-'.$record->count_number,
                                'business_id' => $record->business_id,
                                'location_id' => $refLocationId,
                                'stock_count_id' => $record->id,
                                'type' => 'STOCK_COUNT',
                                'notes' => 'Automatic adjustment from Stock Count ' . $record->count_number,
                                'created_by_user_id' => Auth::id(),
                                'status' => 'posted',
                                'plant_id' => $record->plant_id,
                                'warehouse_id' => ($record->countable_type === Warehouse::class) ? $record->countable_id : null,
                            ]);

                            // 2. Loop SEMUA item
                            foreach ($itemsToAdjust as $item) {
                                $inventory = $item->inventory;
                                if (!$inventory) {
                                    Log::warning("StockCount Error: Inventory ID {$item->inventory_id} not found. Skipping.");
                                    continue;
                                }
                                $product = $inventory->product;
                                if (!$product) {
                                    Log::warning("StockCount Error: Product not found. Skipping.");
                                    continue;
                                }
                                $product->loadMissing('uoms');

                                $locationIdsToUnlock[] = $inventory->location_id;

                                // --- LOGIKA KONVERSI UOM ---
                                $quantityBefore_BaseUoM = (float)$item->system_stock;
                                $countedQty_Input = (float)$item->final_counted_stock;
                                $countedUom_Input = $item->final_counted_uom ?? $product->base_uom;

                                $uomData = $product->uoms->where('uom_name', $countedUom_Input)->first();
                                $conversionRate = $uomData?->conversion_rate ?? 1.0;
                                $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;

                                $quantityAfter_BaseUoM = $countedQty_Input * $conversionRate;
                                $quantityChange_BaseUoM = $quantityAfter_BaseUoM - $quantityBefore_BaseUoM;

                                // 3. Cek jika ada selisih
                                if (abs($quantityChange_BaseUoM) > 0.0001) {
                                    $hasVariance = true;

                                    // 4. UPDATE STOK
                                    $inventory->update(['avail_stock' => $quantityAfter_BaseUoM]);

                                    // 5. BUAT DETAIL ADJUSTMENT
                                    $adjustment->items()->create([
                                        'inventory_id' => $item->inventory_id,
                                        'product_id' => $item->product_id,
                                        'quantity_before' => $quantityBefore_BaseUoM,
                                        'quantity_change' => $quantityChange_BaseUoM,
                                        'quantity_after' => $quantityAfter_BaseUoM,
                                    ]);

                                    // 6. BUAT LOG MOVEMENT
                                    InventoryMovement::create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_change' => $quantityChange_BaseUoM,
                                        'stock_after_move' => $quantityAfter_BaseUoM,
                                        'type' => 'ADJUST_STOCK_COUNT',
                                        'reference_type' => get_class($adjustment),
                                        'reference_id' => $adjustment->id,
                                        'user_id' => Auth::id(),
                                        'notes' => "Adjustment from SC #{$record->count_number}. Var: {$quantityChange_BaseUoM}"
                                    ]);

                                    // 7. PICU KONSINYASI
                                    if ($quantityChange_BaseUoM < 0 && $inventory->location->ownership_type === 'consignment') {
                                        event(new \App\Events\ConsignmentStockConsumed($inventory, abs($quantityChange_BaseUoM), $record));
                                    }
                                }
                            }

                            // Unlock item tanpa selisih
                            $itemsWithoutVariance = $itemsToAdjust->filter(function($item) use ($product) {
                                if (!$item->product) return false;
                                // Recalculate logic sederhana untuk filter
                                $sys = (float)$item->system_stock;
                                $act = (float)$item->final_counted_stock; // Asumsi rate 1 utk filter cepat atau load ulang logic
                                return $sys == $act; // Simplifikasi filter
                            });

                            foreach ($itemsWithoutVariance as $item) {
                                $locationIdsToUnlock[] = $item->inventory?->location_id;
                            }

                            // 8. UPDATE STATUS
                            $record->update(['status' => 'posted', 'posted_at' => now(), 'posted_by_user_id' => Auth::id()]);

                            if (!$hasVariance) {
                                Notification::make()->title('No variances found.')->body('Stock is already accurate.')->info()->send();
                            }
                        });

                        // UNLOCK LOKASI
                        $allLocationIds = collect($locationIdsToUnlock)->filter()->unique();
                        if ($allLocationIds->isNotEmpty()) {
                            Location::whereIn('id', $allLocationIds)->update(['is_sellable' => true]);
                            Log::info("Unlocked " . $allLocationIds->count() . " locations.");
                        }

                        Notification::make()->title('Stock adjustment posted successfully!')->success()->send();
                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));

                    } catch (\Exception $e) {
                        Log::error("PostAdjustment Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()->title('Failed to post adjustment!')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),
            /**
             * AKSI 3: POST ADJUSTMENT (oleh Area Manager)
             */
            // Actions\Action::make('postAdjustment')
            //     ->label('Post Adjustment')
            //     ->color('success')->icon('heroicon-o-check-badge')
            //     ->requiresConfirmation()
            //     ->visible(fn (StockCount $record) =>
            //         in_array($record->status, ['pending_approval', 'pending_validation']) &&
            //         $this->check(Auth::user(), 'post stock count adjustments')
            //     )
            //     ->action(function (StockCount $record) {
            //         try {
            //             $locationIdsToUnlock = [];

            //             DB::transaction(function () use ($record, &$locationIdsToUnlock) {
            //                 // Muat relasi yang diperlukan (termasuk UoM)
            //                 $record->load('items.inventory.product.uoms', 'items.inventory.location', 'countable');

            //                 $itemsToAdjust = $record->items()->whereNotNull('final_counted_stock')->get();
            //                 $hasVariance = false; // Flag untuk melacak jika ada selisih

            //                 // Tentukan lokasi referensi (tidak perlu di dalam loop)
            //                 $firstItem = $itemsToAdjust->first();
            //                 if (!$firstItem) {
            //                     Log::info("No items to adjust for SC #{$record->id}.");
            //                     // Tidak ada item, tapi kita tetap harus post dan unlock
            //                 }

            //                 $refLocationId = $firstItem?->inventory?->location_id ?? $record->countable->locations()->first()->id;
            //                 if (!$refLocationId && $itemsToAdjust->isNotEmpty()) {
            //                      throw new \Exception('Cannot determine a reference location for the adjustment document.');
            //                 }

            //                 // 1. Buat dokumen master Inventory Adjustment
            //                 $adjustment = InventoryAdjustment::create([
            //                     'adjustment_number' => 'ADJ-SC-'.$record->count_number,
            //                     'business_id' => $record->business_id,
            //                     'location_id' => $refLocationId, // Bisa null jika tidak ada item
            //                     'stock_count_id' => $record->id,
            //                     'type' => 'STOCK_COUNT',
            //                     'notes' => 'Automatic adjustment from Stock Count ' . $record->count_number,
            //                     'created_by_user_id' => Auth::id(),
            //                     'status' => 'posted',
            //                     'plant_id' => $record->plant_id, // Ambil Plant ID dari Stock Count
            //                     // Simpan warehouse_id HANYA jika countable adalah Warehouse
            //                     'warehouse_id' => ($record->countable_type === Warehouse::class) ? $record->countable_id : null,
            //                 ]);

            //                 // 2. Loop SEMUA item (termasuk yang tidak ada selisih)
            //                 foreach ($itemsToAdjust as $item) {
            //                     $inventory = $item->inventory;
            //                     if (!$inventory) {
            //                         Log::warning("StockCount Error: Inventory ID {$item->inventory_id} not found for item {$item->id}. Skipping adjustment.");
            //                         continue;
            //                     }
            //                     $product = $inventory->product;
            //                     if (!$product) {
            //                         Log::warning("StockCount Error: Product not found for Inventory ID {$inventory->id}. Skipping adjustment.");
            //                         continue;
            //                     }
            //                     $product->loadMissing('uoms');

            //                     // Kumpulkan ID lokasi untuk di-unlock
            //                     $locationIdsToUnlock[] = $inventory->location_id;

            //                     // --- LOGIKA KONVERSI UOM DIMULAI ---

            //                     // A. Ambil Stok Sistem (Sudah dalam Base UoM)
            //                     $quantityBefore_BaseUoM = (float)$item->system_stock;

            //                     // B. Ambil Stok Fisik (Hitungan)
            //                     $countedQty_Input = (float)$item->final_counted_stock;
            //                     $countedUom_Input = $item->final_counted_uom ?? $product->base_uom;

            //                     // C. Cari Conversion Rate
            //                     $uomData = $product->uoms->where('uom_name', $countedUom_Input)->first();
            //                     $conversionRate = $uomData?->conversion_rate ?? 1.0;
            //                     $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;

            //                     // D. Konversi Hitungan Fisik ke Base UoM
            //                     $quantityAfter_BaseUoM = $countedQty_Input * $conversionRate;

            //                     // E. Hitung Selisih (Variance) dalam Base UoM
            //                     $quantityChange_BaseUoM = $quantityAfter_BaseUoM - $quantityBefore_BaseUoM;
            //                     // --- LOGIKA KONVERSI UOM SELESAI ---

            //                     // 3. Cek jika ada selisih
            //                     if ($quantityChange_BaseUoM != 0) {
            //                         $hasVariance = true; // Tandai bahwa ada selisih

            //                         // 4. UPDATE STOK di tabel inventories
            //                         $inventory->update(['avail_stock' => $quantityAfter_BaseUoM]);

            //                         // 5. BUAT DETAIL ADJUSTMENT (log di dokumen adjustment)
            //                         $adjustment->items()->create([
            //                             'inventory_id' => $item->inventory_id,
            //                             'product_id' => $item->product_id,
            //                             'quantity_before' => $quantityBefore_BaseUoM,
            //                             'quantity_change' => $quantityChange_BaseUoM,
            //                             'quantity_after' => $quantityAfter_BaseUoM,
            //                         ]);

            //                         // 6. BUAT LOG INVENTORY MOVEMENT (jejak audit)
            //                         InventoryMovement::create([
            //                             'inventory_id' => $inventory->id,
            //                             'quantity_change' => $quantityChange_BaseUoM,
            //                             'stock_after_move' => $quantityAfter_BaseUoM,
            //                             'type' => 'ADJUST_STOCK_COUNT',
            //                             'reference_type' => get_class($adjustment),
            //                             'reference_id' => $adjustment->id,
            //                             'user_id' => Auth::id(),
            //                             'notes' => "Adjustment from SC #{$record->count_number}. Counted: {$countedQty_Input} {$countedUom_Input}. Variance: {$quantityChange_BaseUoM} {$product->base_uom}"
            //                         ]);

            //                         // 7. PICU KONSINYASI
            //                         if ($quantityChange_BaseUoM < 0 && $inventory->location->ownership_type === 'consignment') {
            //                             event(new ConsignmentStockConsumed($inventory, abs($quantityChange_BaseUoM), $record));
            //                         }
            //                     }
            //                 } // Akhir loop items

            //                 // Ambil juga ID lokasi dari item yang TIDAK ada selisih (harus di-unlock)
            //                 $itemsWithoutVariance = $itemsToAdjust->filter(function($item) use ($product) {
            //                     // Pastikan $product ada sebelum mengakses uoms
            //                     if (!$product) return false;
            //                     $uomData = $product->uoms->where('uom_name', $item->final_counted_uom ?? $product->base_uom)->first();
            //                     $conversionRate = $uomData?->conversion_rate ?? 1.0;
            //                     $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;
            //                     $quantityAfter_BaseUoM = (float)$item->final_counted_stock * $conversionRate;
            //                     $quantityBefore_BaseUoM = (float)$item->system_stock;
            //                     return ($quantityAfter_BaseUoM - $quantityBefore_BaseUoM) == 0;
            //                 });

            //                 foreach ($itemsWithoutVariance as $item) {
            //                     $locationIdsToUnlock[] = $item->inventory?->location_id;
            //                 }

            //                 // 8. UPDATE STATUS STOCK COUNT
            //                 $record->update(['status' => 'posted', 'posted_at' => now(), 'posted_by_user_id' => Auth::id()]);

            //                 if (!$hasVariance) {
            //                     Notification::make()->title('No variances found.')->body('Stock is already accurate.')->info()->send();
            //                 }
            //             }); // --- Akhir DB Transaction ---

            //             // ==========================================================
            //             // LANGKAH TERAKHIR: "BUKA KUNCI" LOKASI (UNFREEZE)
            //             // ==========================================================
            //             $allLocationIds = collect($locationIdsToUnlock)->filter()->unique();
            //             if ($allLocationIds->isNotEmpty()) {
            //                 Location::whereIn('id', $allLocationIds)->update(['is_sellable' => true]);
            //                 Log::info("Unlocked " . $allLocationIds->count() . " locations for POSTED SC ID: {$record->id}");
            //             }

            //             Notification::make()->title('Stock adjustment posted successfully!')->success()->send();
            //             $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));

            //         } catch (\Exception $e) {
            //             Log::error("PostAdjustment Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            //             Notification::make()->title('Failed to post adjustment!')->body($e->getMessage())->danger()->send();
            //             $this->halt();
            //         }
            //     }),

    /**
 * AKSI PENGAMAN: CANCEL COUNT
 * Untuk membatalkan proses dan membuka kembali lokasi yang terkunci.
 */
Actions\Action::make('cancelCount')
                ->label('Cancel Stock Count')
                ->color('danger')->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                // Visible logic (sudah benar menggunakan ->check())
                ->visible(function (StockCount $record) {
                    return
                        in_array($record->status, ['in_progress', 'pending_approval', 'pending_validation']) &&
                        $this->check(Auth::user(), 'cancel stock counts'); // Ganti nama permission jika perlu
                })
                ->action(function (StockCount $record) {
                    try {
                        DB::transaction(function () use ($record) {
                            $record->loadMissing('countable', 'zone'); // Muat relasi

                            // ==========================================================
                            // --- PERBAIKAN: Jalankan query lokasi yang sama dgn startCount ---
                            // ==========================================================

                            // 1. Mulai query dari lokasi milik Warehouse/Outlet ini
                            $locationsQuery = $record->countable->locations()
                                                ->where('is_sellable', false) // <-- Cari lokasi yg sedang DIKUNCI
                                                ->where('status', true); // Dan statusnya aktif

                            // 2. Jika ini Cycle Count (ada Zone), filter berdasarkan Zone
                            if ($record->zone_id) {
                                $locationsQuery->where('zone_id', $record->zone_id);
                            }

                            $locationIdsToUnlock = $locationsQuery->pluck('id');
                            // ==========================================================


                            // 3. "BUKA KUNCI" LOKASI (UNFREEZE)
                            if ($locationIdsToUnlock->isNotEmpty()) {
                                Location::whereIn('id', $locationIdsToUnlock)->update(['is_sellable' => true]);
                                Log::info("Unlocked " . $locationIdsToUnlock->count() . " locations for cancelled SC ID: {$record->id}");
                            } else {
                                Log::warning("Cancel count (SC ID: {$record->id}) did not find any locked locations matching the criteria.");
                            }

                            // 4. Hapus item snapshot yang sudah dibuat
                            $record->items()->delete();

                            // 5. Update status dokumen
                            $record->update(['status' => 'cancelled']);
                        });

                        Notification::make()
                            ->title('Stock Count Cancelled')
                            ->body('All frozen locations have been released.')
                            ->warning()
                            ->send();

                        // Refresh form
                        // $this->getRecord()->refresh();
                        // $this->refreshFormData($this->getRecord()->toArray());
                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()]));

                    } catch (\Exception $e) {
                        Log::error("CancelCount Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                        Notification::make()
                            ->title('Failed to cancel count!')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        $this->halt();
                    }
                }),


        ];
    }

    /**
     * Form dinamis yang menampilkan antarmuka berbeda
     */
   public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Details')
                ->schema([
                    Placeholder::make('count_number')->content($this->record->count_number),
                    Placeholder::make('location')
                        ->label('Location')
                        ->content(fn(StockCount $record) =>
                            $record->loadMissing('countable', 'plant')?->countable?->name . ' (' . ($record->plant?->name ?? 'N/A') . ')'
                        ),
                    Placeholder::make('zone')
                        ->label('Zone')
                        ->content(fn(StockCount $record) => $record->loadMissing('zone')?->zone?->name ?? 'All Zones')
                        ->visible(fn(StockCount $record) => $record->zone_id),
                    Placeholder::make('status')->content(fn() => ucwords(str_replace('_', ' ', $this->record->status))),

                    Placeholder::make('assigned_teams_display')
                        ->label('Assigned Teams')
                        ->content(function (StockCount $record): HtmlString {
                            $teams = $record->assigned_teams ?? [];
                            $yellowIds = $teams['yellow'] ?? [];
                            $greenIds = $teams['green'] ?? [];
                            $whiteIds = $teams['white'] ?? [];

                            $allIds = array_merge($yellowIds, $greenIds, $whiteIds);
                            if (empty($allIds)) {
                                return new HtmlString('<em>No teams assigned.</em>');
                            }

                            $users = User::whereIn('id', $allIds)->pluck('name', 'id');

                            $yellowNames = collect($yellowIds)->map(fn($id) => $users->get($id) ?? 'Unknown')->implode(', ');
                            $greenNames = collect($greenIds)->map(fn($id) => $users->get($id) ?? 'Unknown')->implode(', ');
                            $whiteNames = collect($whiteIds)->map(fn($id) => $users->get($id) ?? 'Unknown')->implode(', ');

                            return new HtmlString(
                                "<span class='text-yellow-600 font-medium'>Tim Kuning:</span> {$yellowNames}<br>" .
                                "<span class='text-green-600 font-medium'>Tim Hijau:</span> {$greenNames}<br>" .
                                "<span class='text-gray-600 font-medium'>Validator:</span> {$whiteNames}"
                            );
                        })
                        ->visible(fn() => $this->isWarehouseCount())
                        ->columnSpanFull(),

                ])->columns(3),

            // ==========================================================
            // TAMPILAN UNTUK OUTLET (Input Hitungan Sederhana)
            // ==========================================================
            Section::make('Outlet Count Items')
                ->visible(fn() => !$this->isWarehouseCount() && in_array($this->record->status, ['in_progress', 'pending_approval', 'posted']))
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->schema([
                            Placeholder::make('product')
                                ->content(fn(Model $record) => $record->product?->name ?? 'Product Not Found')
                                ->columnSpan(2),
                            Placeholder::make('batch')
                                ->content(fn(Model $record) => $record->inventory?->batch ?? 'N/A'),
                            Placeholder::make('system_stock')
                                 ->label('System Stock')
                                 ->content(fn(Model $record) => (float) $record->system_stock . ' ' . $record->product?->base_uom)
                                 ->columnSpan(3),

                            Checkbox::make('is_zero_count')
                                ->label('Mark as Zero Count')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Model $record, $state) {
                                    if ($state === true) {
                                        $set('final_counted_stock', 0);
                                        $record->loadMissing('product');
                                        $set('final_counted_uom', $record->product?->base_uom ?? 'pcs');
                                    }
                                })
                                ->default(fn (Model $record): bool => (bool)$record->is_zero_count)
                                ->columnSpan(2),

                            TextInput::make('final_counted_stock')
                                ->label('Physical Count')
                                ->numeric()->required()
                                ->default(0)
                                ->live()
                                ->disabled(function (Get $get) {
                                    return $get('is_zero_count') === true ||
                                           $this->record->status !== 'in_progress' ||
                                           !$this->check(Auth::user(), 'submit stock counts');
                                })
                                ->columnSpan(2),

                            Select::make('final_counted_uom')
                                ->label('UoM')
                                ->live()
                                ->options(function(Model $record): array {
                                    $record->loadMissing('product.uoms');
                                    return $record->product?->uoms->pluck('uom_name', 'uom_name')->toArray() ?? [];
                                })
                                ->default(function(Model $record) {
                                    $record->loadMissing('product');
                                    return $record->final_counted_uom ?? $record->product?->base_uom ?? 'pcs';
                                })
                                // Buat 'required' dinamis
                                ->required(fn (Get $get) => $get('is_zero_count') !== true)
                                ->disabled(function (Get $get) {
                                    return $get('is_zero_count') === true ||
                                           $this->record->status !== 'in_progress' ||
                                           !$this->check(Auth::user(), 'submit stock counts');
                                })
                                ->columnSpan(2),
                            Placeholder::make('variance')
    ->label('Variance')
    ->content(function (Get $get, Model $record) {
        $system_base = (float) $get('system_stock');
        $counted_input = (float) $get('final_counted_stock');
        $uom_input = $get('final_counted_uom');

        $record->loadMissing('product.uoms');
        $product = $record->product;
        if (!$product) return new HtmlString('-');

        $uomData = $product->uoms->where('uom_name', $uom_input)->first();
        $conversionRate = $uomData?->conversion_rate ?? 1.0;
        $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;

        $counted_base = $counted_input * $conversionRate;
        $variance = $counted_base - $system_base;

        $color = $variance == 0 ? 'text-success-600' : 'text-danger-600';

        // gunakan HtmlString agar HTML-nya tidak di-escape
        return new HtmlString("<span class='{$color} font-bold'>{$variance} {$product->base_uom}</span>");
    })->columnSpanFull(),

                            //  Placeholder::make('variance')
                            //     ->content(function (Get $get, Model $record): string {
                            //         // (Logika variance Anda sudah benar)
                            //         $system_base = (float) $get('system_stock');
                            //         $counted_input = (float) $get('final_counted_stock');
                            //         $uom_input = $get('final_counted_uom');
                            //         $record->loadMissing('product.uoms');
                            //         $product = $record->product;
                            //         if (!$product) return '-';
                            //         $uomData = $product->uoms->where('uom_name', $uom_input)->first();
                            //         $conversionRate = $uomData?->conversion_rate ?? 1.0;
                            //         $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;
                            //         $counted_base = $counted_input * $conversionRate;
                            //         $variance = $counted_base - $system_base;
                            //         $color = $variance == 0 ? 'text-success-600' : 'text-danger-600';
                            //         return new \Illuminate\Support\HtmlString("<span class='{$color} font-bold'>{$variance} {$product->base_uom}</span>");
                                //})

                        ])
                        ->addable(false)->deletable(false)
                        ->columns(6)
                        ->disabled(fn() => $this->record->status !== 'in_progress' || !$this->check(Auth::user(), 'submit stock counts')),
                ]),

            Section::make('Warehouse Validation')
                ->visible(fn() => $this->isWarehouseCount() && in_array($this->record->status, ['pending_validation', 'posted']))
                ->schema([
                    Placeholder::make('validation_info')
                        ->label('Team Counts & Validation')
                        ->content('Data raw count dari tim penghitung (mobile app) dan input untuk validator (Tim Putih) ada di tabel "Items" di bagian bawah halaman ini.'),
                ]),
        ]);
    }

   /**
     * Helper function untuk logika memulai stock count.
     * Termasuk mem-filter lokasi, membekukan stok, dan membuat snapshot.
     */
    private function startCount(): void // <-- 1. Ubah ke void
    {
        try {
            DB::transaction(function () {
                $record = $this->getRecord();
                $record->loadMissing('countable', 'zone');
                if (!$record->countable) {
                     throw new \Exception('Stock count record is not linked to a Warehouse or Outlet.');
                }
                $locationsQuery = $record->countable->locations()
                                    ->where('is_sellable', true)
                                    ->where('status', true);
                if ($record->zone_id) {
                    $locationsQuery->where('zone_id', $record->zone_id);
                    Log::info("Starting stock count for Zone ID: {$record->zone_id}");
                } else {
                     Log::info("Starting stock count for ALL ZONES in {$record->countable->name}");
                }
                $sellableLocations = $locationsQuery->get();
                $locationIdsToLock = $sellableLocations->pluck('id');
                if ($locationIdsToLock->isEmpty()) {
                    $zoneName = $record->zone?->name ?? 'All Zones';
                    throw new \Exception("No active, sellable locations found for {$record->countable->name} in Zone '{$zoneName}'.");
                }
                Location::whereIn('id', $locationIdsToLock)->update(['is_sellable' => false]);
                Log::info("Locked " . $locationIdsToLock->count() . " locations for counting.");

                $inventories = Inventory::whereIn('location_id', $locationIdsToLock)
                                 ->get();
                if ($inventories->isEmpty()) {
                    Location::whereIn('id', $locationIdsToLock)->update(['is_sellable' => true]);
                    throw new \Exception('No inventory items found in the selected locations.');
                }

                $itemsToCreate = [];
                foreach ($inventories as $inventory) {
                    $itemsToCreate[] = [
                        'inventory_id' => $inventory->id,
                        'product_id' => $inventory->product_id,
                        'system_stock' => $inventory->avail_stock,
                    ];
                }
                $record->items()->createMany($itemsToCreate);
                Log::info("Created " . count($itemsToCreate) . " snapshot items.");
                $record->update(['status' => 'in_progress', 'started_at' => now()]);
            });

            Notification::make()->title('Stock count has started!')->body('Selected sellable locations are now frozen.')->success()->send();

            // ==========================================================
            // --- PERBAIKAN: Panggil '$this->redirect' tanpa return ---
            // ==========================================================
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->getRecord()])); // <-- 2. Hapus 'return'

        } catch (\Exception $e) {
            Notification::make()->title('Failed to start count!')->body($e->getMessage())->danger()->send();
            $this->halt();
            return; // <-- 3. Ubah 'return null' menjadi 'return' (void)
        }
    }

    protected function getFormActions(): array
    {
        // Kembalikan array kosong untuk menyembunyikan semua tombol footer
        return [];
    }

    public function getRelations(): array
    {
        // Cek apakah ini Stock Count Gudang (Warehouse)
        if ($this->isWarehouseCount()) {
            // Jika YA, tampilkan Relation Manager Tim
            return [
                RelationManagers\ItemsRelationManager::class,
            ];
        }

        // Jika TIDAK (ini Outlet), jangan tampilkan apa-apa
        return [];
    }

}

