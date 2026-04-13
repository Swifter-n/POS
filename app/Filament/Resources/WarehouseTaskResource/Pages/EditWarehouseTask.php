<?php

namespace App\Filament\Resources\WarehouseTaskResource\Pages;

use App\Filament\Resources\WarehouseTaskResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditWarehouseTask extends EditRecord
{
    use HasPermissionChecks;
    protected static string $resource = WarehouseTaskResource::class;

    /**
     * Form() tidak berubah. Ini hanya menampilkan info header.
     */
    public function form(Form $form): Form
    {
        $record = $this->getRecord();
        $record->loadMissing('plant', 'fromWarehouse', 'sourceLocation', 'assignedUser');

        return $form->schema([
            Section::make('Put-Away Task Details')
                ->schema([
                    Placeholder::make('transfer_number')->content($record->transfer_number),
                    Placeholder::make('plant')->content($record->plant?->name),
                    Placeholder::make('warehouse')
                        ->label('Warehouse')
                        ->content($record->fromWarehouse?->name),
                    Placeholder::make('source_location_id')
                        ->label('Source Location (From)')
                        ->content($record->sourceLocation?->name),

                    // Helper Sisa Kuantitas (agar user tahu sisa pekerjaan)
                    Placeholder::make('remaining_work')
                        ->label('Remaining Work')
                        ->content(function ($record) {
                            // Perlu di-refresh untuk mendapatkan data terbaru
                            $record->loadMissing('items.product.uoms', 'items.putAwayEntries');
                            $totalRemaining = 0;
                            $baseUom = 'PCS';

                            foreach ($record->items as $item) {
                                // 1. Hitung total yang diminta (dalam base UoM)
                                $reqUom = $item->product?->uoms->where('uom_name', $item->uom)->first();
                                $totalRequiredBase = (float)$item->quantity * ($reqUom?->conversion_rate ?? 1);
                                $baseUom = $item->product?->base_uom ?? 'PCS';

                                // 2. Hitung total yang sudah di-log (dalam base UoM)
                                $totalMoved = (float)$item->putAwayEntries->sum('quantity_moved');

                                $totalRemaining += ($totalRequiredBase - $totalMoved);
                            }
                            return $totalRemaining > 0 ? "{$totalRemaining} {$baseUom} (Across all items)" : "All items logged";
                        }),
                        // ->poll() DIHAPUS

                    Placeholder::make('status')->content(fn() => ucwords(str_replace('_', ' ', $record->status))),

                    Placeholder::make('assigned_user_id')
                         ->label('Assigned To')
                         ->content($record->assignedUser?->name ?? 'N/A'),
                    Placeholder::make('notes')->content($record->notes)->columnSpanFull(),
                ])->columns(3),
        ]);
    }

protected function getFormActions(): array
    {
        return [];
    }


    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord(); // Ini adalah StockTransfer (PA-...)
        $isPutAway = true;
        return [
            Actions\Action::make('assignPicker')
                ->label('Assign Picker')
                ->icon('heroicon-o-user')
                ->color('warning')
                ->visible(fn () => $record->status === 'draft') // Permission check here
                ->form([
                    Select::make('assigned_user_id')
                        ->label('Select Staff')
                        ->options(User::where('business_id', $user->business_id)->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, StockTransfer $record) { // Tambahkan $record

        $record->update([
            'status' => 'pending_pick',
            'assigned_user_id' => $data['assigned_user_id'],
        ]);

        $picker = User::find($data['assigned_user_id']);

        if ($picker) {
            $picker->notify(new \App\Notifications\TaskAssignedNotification(
                'Putaway',              // Tipe Tugas
                $record->transfer_number, // Nomor Dokumen
                $record->id               // ID Referensi
            ));
        }

        Notification::make()->title('Task assigned to picker.')->success()->send();
    }),

            // --- ACTION 2: START TASK ---
            Actions\Action::make('startPutAway')
                ->label('Start Task')
                ->icon('heroicon-o-play')
                ->color('info')
                ->visible(fn () => $record->status === 'pending_pick' && $record->assigned_user_id === $user->id)
                ->action(fn () => $record->update(['status' => 'in_progress', 'started_at' => now()])),

            // --- ACTION 3: EXECUTE / FINISH TASK (UPDATE INVENTORY) ---
            Actions\Action::make('executePutAway')
                ->label('Finish & Post Inventory')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => $record->status === 'in_progress')
                ->action(function (StockTransfer $record) {
                    try {
                        DB::transaction(function () use ($record) {
                            $userId = Auth::id();

                            // Loop Entry (Hasil Scan Picker)
                            foreach ($record->putAwayEntries as $entry) {
                                $qtyMoved = (float) $entry->quantity_moved;
                                if ($qtyMoved <= 0) continue;

                                $sourceLocId = $record->source_location_id;
                                $destLocId = $entry->destination_location_id; // Lokasi Aktual pilihan picker

                                // 1. Kurangi Stok di Source (RCV) - FEFO Logic
                                $sourceInventories = Inventory::where('location_id', $sourceLocId)
                                    ->where('product_id', $entry->product_id)
                                    ->where('avail_stock', '>', 0)
                                    ->orderBy('sled', 'asc')
                                    ->get();

                                $remainingToMove = $qtyMoved;

                                foreach ($sourceInventories as $inv) {
                                    if ($remainingToMove <= 0) break;
                                    $qtyBatch = min($remainingToMove, $inv->avail_stock);

                                    $inv->decrement('avail_stock', $qtyBatch);

                                    // Log Movement Out
                                    InventoryMovement::create([
                                        'inventory_id' => $inv->id, 'quantity_change' => -$qtyBatch,
                                        'stock_after_move' => $inv->avail_stock, 'type' => 'PUTAWAY_OUT',
                                        'reference_type' => StockTransfer::class, 'reference_id' => $record->id,
                                        'user_id' => $userId
                                    ]);

                                    // 2. Tambah Stok di Destinasi (Rak)
                                    $destInv = Inventory::firstOrCreate(
                                        ['location_id' => $destLocId, 'product_id' => $inv->product_id, 'batch' => $inv->batch],
                                        ['business_id' => $record->business_id, 'sled' => $inv->sled, 'avail_stock' => 0]
                                    );
                                    $destInv->increment('avail_stock', $qtyBatch);

                                    // Log Movement In
                                    InventoryMovement::create([
                                        'inventory_id' => $destInv->id, 'quantity_change' => $qtyBatch,
                                        'stock_after_move' => $destInv->avail_stock, 'type' => 'PUTAWAY_IN',
                                        'reference_type' => StockTransfer::class, 'reference_id' => $record->id,
                                        'user_id' => $userId
                                    ]);

                                    $remainingToMove -= $qtyBatch;
                                }

                                // 3. UPDATE KAPASITAS BIN (Pallet Count)
                                // Asumsi: Setiap entry adalah perpindahan 1 Pallet/Handling Unit
                                // Logic ini bisa diperhalus jika 1 entry = 100 pcs curah.
                                // Untuk "SAP Style", biasanya pergerakan adalah per Pallet.

                                $destinationLoc = Location::find($destLocId);
                                if ($destinationLoc) {
                                    // Tambah counter isi bin
                                    $destinationLoc->increment('current_pallets', 1);
                                    // Opsi: Simpan Pallet ID (HU) ke bin jika menggunakan tabel HandlingUnit
                                }
                            }

                            $record->update(['status' => 'completed', 'completed_at' => now()]);
                        });

                        Notification::make()->title('Putaway Completed')->success()->send();
                        $this->redirect(WarehouseTaskResource::getUrl('index'));

                    } catch (\Exception $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];

    //     return [
    //         /**
    //          * Aksi 'Assign' (Di sinilah letak bug-nya)
    //          */
    //         Actions\Action::make('assignPicker')
    // ->label('Assign Put-Away Task')
    // ->color('info')->icon('heroicon-o-user-plus')
    // ->visible(fn ($record) => $record->status === 'draft' && $this->check($user, 'execute internal transfers')) // Ganti permission jika perlu

    // // ==========================================================
    // // --- PERBAIKAN: Ubah 'form' menjadi Closure ---
    // // ==========================================================
    // ->form(function (StockTransfer $record): array { // $record adalah StockTransfer (Task)
    //     return [
    //         Select::make('assigned_user_id')
    //             ->label('Assign Task To (Picker)')

    //             // ==========================================================
    //             // --- PERBAIKAN: Tambahkan 'use ($record)' dan filter kueri ---
    //             // ==========================================================
    //             ->options(function () use ($record) {

    //                 // 1. Ambil Plant ID dari Task Put-Away ini
    //                 $taskPlantId = $record->plant_id; // (Task ini punya plant_id)

    //                 if (!$taskPlantId) {
    //                     Log::warning("Put-Away Task {$record->id} has no plant_id. Cannot filter pickers.");
    //                     return []; // Kembalikan kosong jika task tidak punya plant
    //                 }

    //                 // 2. Kueri User (Karyawan)
    //                 $query = User::whereHas('position', fn($q) => $q->whereIn('name', ['Staff Gudang', 'Manager Gudang'])) // (Filter posisi)
    //                              ->where('status', true); // (Selalu filter user aktif)

    //                 // ==========================================================
    //                 // --- PERBAIKAN: Filter berdasarkan 'plant_id' Karyawan ---
    //                 // (Sesuai referensi EmployeeResource.php Anda)
    //                 // ==========================================================
    //                 $query->where('plant_id', $taskPlantId);

    //                 return $query->whereNotNull('name')->pluck('name', 'id');
    //             })
    //             // ==========================================================

    //             ->searchable()->required(),
    //     ];
    // })
    // ->action(function (array $data, StockTransfer $record) { // <-- [PERBAIKAN] Tambahkan $record
    //     $record->update([
    //         'status' => 'pending_pick',
    //         'assigned_user_id' => $data['assigned_user_id'],
    //     ]);
    //     Notification::make()->title('Task assigned to picker.')->success()->send();
    //     return redirect($this->getResource()::getUrl('edit', ['record' => $record, 'status' => 'pending_pick', 'assigned_user_id' => $data['assigned_user_id']]));
    //    // $this->refreshFormData(['status', 'assigned_user_id']);

    // }),

    //         Actions\Action::make('startPutAway')
    //             ->label('Start Put-Away')
    //             ->color('info')->icon('heroicon-o-play')
    //             ->requiresConfirmation()
    //             ->visible(fn ($record) =>
    //                 $record->status === 'pending_pick' &&
    //                 $record->assigned_user_id === $user->id
    //             )
    //             ->action(function () use ($record) {
    //                 try {
    //                     // ==========================================================
    //                     // --- LOGIKA VALIDASI STOK (SESUAI PERMINTAAN ANDA) ---
    //                     // ==========================================================
    //                     $record->loadMissing('items.product.uoms', 'sourceLocation');
    //                     $sourceLocationId = $record->source_location_id;
    //                     if (!$sourceLocationId) {
    //                         throw new \Exception('Source location (RCV) is not set on this task.');
    //                     }

    //                     foreach ($record->items as $item) {
    //                         $product = $item->product;
    //                         if (!$product) continue;

    //                         // 1. Hitung total yang diminta (dalam base UoM)
    //                         $reqUom = $product->uoms->where('uom_name', $item->uom)->first();
    //                         if (!$reqUom) {
    //                             // Fallback jika UoM asli tidak ditemukan (seharusnya tidak terjadi)
    //                             $reqUom = $product->uoms->where('uom_name', $product->base_uom)->first();
    //                         }
    //                         $totalRequiredBase = (float)$item->quantity * ($reqUom?->conversion_rate ?? 1);
    //                         if ($totalRequiredBase <= 0) continue;

    //                         // 2. Hitung total stok yang ada di lokasi sumber (RCV)
    //                         // (Ini mengecek total stok produk, bukan per-batch,
    //                         //  karena 'executePutAway' akan mengambil FEFO)
    //                         $totalStockAtSource = Inventory::where('location_id', $sourceLocationId)
    //                                                     ->where('product_id', $product->id)
    //                                                     ->sum('avail_stock');

    //                         // 3. Bandingkan
    //                         if ((float)$totalStockAtSource < $totalRequiredBase) {
    //                             $missing = $totalRequiredBase - (float)$totalStockAtSource;
    //                             throw new \Exception("Stock for '{$product->name}' is insufficient at source location '{$record->sourceLocation->name}'. Missing {$missing} {$product->base_uom}.");
    //                         }
    //                     }
    //                     // --- AKHIR VALIDASI STOK ---
    //                     // ==========================================================

    //                     $record->update([
    //                         'status' => 'in_progress',
    //                         'started_at' => now(), // Mencatat waktu mulai
    //                     ]);
    //                     Notification::make()->title('Put-Away started!')->success()->send();

    //                     // Paksa Full Redirect untuk menghindari bug cache
    //                     return redirect($this->getResource()::getUrl('edit', ['record' => $record]));

    //                 } catch (\Exception $e) {
    //                     Notification::make()->title('Cannot Start Put-Away!')->body($e->getMessage())->danger()->send();
    //                     $this->halt();
    //                 }
    //             }),

    //         // ==========================================================
    //         // --- LOGIKA BARU UNTUK 'executePutAway' (STEP 5) ---
    //         // ==========================================================
    //         Actions\Action::make('executePutAway')
    //             ->label('Execute Put-Away')
    //             ->color('success')->icon('heroicon-o-check-circle')
    //             ->requiresConfirmation()
    //             ->modalHeading('Execute Put-Away Task')
    //             ->modalDescription('This will move all logged stock. Are you sure all entries are correct?')
    //             ->visible(fn ($record) =>
    //                 $isPutAway &&
    //                 $record->status === 'in_progress' && // <-- DIPERBARUI
    //                 $record->assigned_user_id === Auth::id()
    //             )
    //             ->action(function (StockTransfer $record) {
    //                 try {
    //                     DB::transaction(function () use ($record) {
    //                         $userId = Auth::id();
    //                         $record->loadMissing([
    //                             'items.product.uoms', // Dibutuhkan untuk validasi
    //                             'items.putAwayEntries', // Dibutuhkan untuk validasi
    //                             'putAwayEntries.destinationLocation', // Dibutuhkan untuk eksekusi
    //                             'sourceLocation' // Dibutuhkan untuk eksekusi
    //                         ]);

    //                         // ==========================================================
    //                         // 1. VALIDASI BARU: Cek apakah semua item sudah di-log
    //                         // ==========================================================
    //                         foreach ($record->items as $item) {
    //                             // 1a. Hitung total yang diminta (dalam base UoM)
    //                             $reqUom = $item->product?->uoms->first(
    //                     fn($uom) => strcasecmp($uom->uom_name, $item->uom) === 0
    //                 );
    //                             if (!$reqUom) {
    //                                 throw new \Exception("UoM data '{$item->uom}' not found for product '{$item->product->name}'. Please check Product UoM master.");
    //                             }
    //                             $totalRequiredBase = (float)$item->quantity * ($reqUom?->conversion_rate ?? 1);

    //                             // 1b. Hitung total yang sudah di-log (dalam base UoM)
    //                             $totalMoved = (float)$item->putAwayEntries->where('stock_transfer_item_id', $item->id)->sum('quantity_moved');

    //                             // 1c. Bandingkan (gunakan pembulatan untuk float)
    //                             if (round($totalMoved, 5) < round($totalRequiredBase, 5)) {
    //                                 $sisa = $totalRequiredBase - $totalMoved;
    //                                 throw new \Exception("Item '{$item->product->name}' is not fully logged. Remaining: {$sisa} {$item->product->base_uom}.");
    //                             }
    //                         }

    //                         // Jika lolos validasi, catat waktu mulai
    //                         $record->update(['status' => 'in_progress', 'started_at' => now()]);
    //                         $sourceLocationId = $record->source_location_id;
    //                         $sourceLocationName = $record->sourceLocation?->name ?? 'Source';

    //                         // ==========================================================
    //                         // 2. EKSEKUSI BARU: Loop per 'PutAwayEntry' (Log)
    //                         // ==========================================================
    //                         foreach($record->putAwayEntries as $entry) {
    //                             $quantityToMove = (float)$entry->quantity_moved;
    //                             $destinationLocation = $entry->destinationLocation;

    //                             if ($quantityToMove <= 0) continue; // Lewati jika entri 0

    //                             // 2a. KURANGI STOK DARI SUMBER (RCV - FEFO)
    //                             $sourceInventories = Inventory::where('location_id', $sourceLocationId)
    //                                 ->where('product_id', $entry->product_id)
    //                                 ->where('avail_stock', '>', 0)
    //                                 ->orderBy('sled', 'asc') // FEFO
    //                                 ->get();

    //                             // Cek kecukupan stok di RCV saat ini
    //                             if ($sourceInventories->sum('avail_stock') < $quantityToMove) {
    //                                 throw new \Exception("Stock for Product ID {$entry->product_id} is no longer sufficient in {$sourceLocationName}. Execution failed.");
    //                             }

    //                             $remainingToMoveFromEntry = $quantityToMove;
    //                             foreach ($sourceInventories as $inventory) {
    //                                 if ($remainingToMoveFromEntry <= 0) break;

    //                                 $qtyFromThisBatch = min($remainingToMoveFromEntry, $inventory->avail_stock);

    //                                 // Kurangi dari RCV
    //                                 $inventory->decrement('avail_stock', $qtyFromThisBatch);
    //                                 InventoryMovement::create([
    //                                     'inventory_id' => $inventory->id, 'quantity_change' => -$qtyFromThisBatch,
    //                                     'stock_after_move' => $inventory->avail_stock, 'type' => 'PUTAWAY_OUT',
    //                                     'reference_type' => StockTransfer::class, 'reference_id' => $record->id,
    //                                     'user_id' => $userId, 'notes' => "Moved from {$sourceLocationName}",
    //                                 ]);

    //                                 // 2b. TAMBAH STOK KE TUJUAN (RAK/BIN)
    //                                 $destinationInventory = Inventory::firstOrCreate(
    //                                     [
    //                                         'location_id' => $destinationLocation->id,
    //                                         'product_id' => $inventory->product_id,
    //                                         'batch' => $inventory->batch
    //                                     ],
    //                                     [
    //                                         'sled' => $inventory->sled,
    //                                         'avail_stock' => 0,
    //                                         'business_id' => $record->business_id
    //                                     ]
    //                                 );
    //                                 $destinationInventory->increment('avail_stock', $qtyFromThisBatch);

    //                                 InventoryMovement::create([
    //                                     'inventory_id' => $destinationInventory->id, 'quantity_change' => $qtyFromThisBatch,
    //                                     'stock_after_move' => $destinationInventory->avail_stock, 'type' => 'PUTAWAY_IN',
    //                                     'reference_type' => StockTransfer::class, 'reference_id' => $record->id,
    //                                     'user_id' => $userId, 'notes' => "Put-away to {$destinationLocation->name}",
    //                                 ]);

    //                                 $remainingToMoveFromEntry -= $qtyFromThisBatch;
    //                             }
    //                         }

    //                         // 3. Selesai: Update status dan catat waktu selesai
    //                         $record->update(['status' => 'completed', 'completed_at' => now()]);

    //                     }); // Akhir DB Transaction

    //                     Notification::make()->title('Put-Away task completed successfully!')->body('Stock has been moved to the destination rack/bin.')->success()->send();

    //                     // Refresh form data agar tombol/status ter-update
    //                     $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
    //                     // $this->getRecord()->refresh();
    //                     // $this->refreshFormData($this->getRecord()->toArray());

    //                 } catch (\Exception $e) {
    //                     Log::error("Put-Away Execution Failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    //                     // Rollback status jika gagal di tengah jalan
    //                      if ($record->status === 'in_progress') {
    //                          $record->update(['status' => 'pending_pick']);
    //                      }
    //                     Notification::make()->title('Put-Away Failed')->body($e->getMessage())->danger()->send();
    //                     $this->halt();
    //                 }
    //             }),
    //     ];
    }
}
