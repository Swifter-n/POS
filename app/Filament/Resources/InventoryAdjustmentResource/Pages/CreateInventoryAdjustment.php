<?php

namespace App\Filament\Resources\InventoryAdjustmentResource\Pages;

use App\Filament\Resources\InventoryAdjustmentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB; // <-- PERBAIKAN 1: Typo diperbaiki
use App\Models\Inventory; // Import
use App\Models\InventoryMovement; // Import
use App\Events\ConsignmentStockConsumed; // Import
use Filament\Notifications\Notification; // Import
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException; // Import
use Illuminate\Support\Facades\Log;

class CreateInventoryAdjustment extends CreateRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['business_id'] = Auth::user()->business_id;
        $data['created_by_user_id'] = Auth::id();
        $data['adjustment_number'] = 'ADJ-' . date('Ym') . '-' . random_int(1000, 9999);
        $data['status'] = 'posted'; // Asumsi adjustment langsung diposting

        // plant_id, warehouse_id, type, notes, dan items sudah ada di $data dari form

        return $data;
    }

    /**
     * Override method handleRecordCreation untuk memproses
     * logika adjustment (update stok dan buat movement) di dalam transaksi DB.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $adjustment = DB::transaction(function () use ($data) {
                // 1. Buat dokumen master Inventory Adjustment
                // Ini menyimpan: adjustment_number, plant_id, warehouse_id, type, notes, status, dll.
                $adjustment = static::getModel()::create($data);

                // 2. Loop setiap item yang di-adjust
                foreach ($data['items'] as $itemData) {

                    // Pastikan item memiliki data yang diperlukan
                    if (empty($itemData['inventory_id']) || !isset($itemData['quantity_after'])) {
                         Log::warning('Skipping adjustment item, missing inventory_id or quantity_after', $itemData);
                         continue;
                    }

                    // Eager load relasi 'location' untuk efisiensi
                    $inventory = Inventory::with('location')->find($itemData['inventory_id']);
                    if (!$inventory) {
                         Log::error("Inventory not found for ID: {$itemData['inventory_id']}. Skipping item.");
                         continue;
                    }

                    // Ambil nilai dari repeater (sudah di-dehydrate)
                    $quantityBefore = (float) $itemData['quantity_before'];
                    $quantityAfter = (float) $itemData['quantity_after'];
                    $quantityChange = (float) $itemData['quantity_change'];

                    // Validasi ulang (pengaman)
                    if (round($quantityChange, 4) != round(($quantityAfter - $quantityBefore), 4)) { // Gunakan round() untuk float
                        throw ValidationException::withMessages(['items' => "Calculation error for item {$inventory->product?->name}."]);
                    }

                    // 3. Update stok di tabel inventories
                    $inventory->update(['avail_stock' => $quantityAfter]);

                    // 4. Buat log pergerakan inventaris
                    InventoryMovement::create([
                        'inventory_id' => $inventory->id,
                        'quantity_change' => $quantityChange,
                        'stock_after_move' => $quantityAfter,
                        'type' => $data['type'], // Tipe dari header (ADJUST_DAMAGE, dll)
                        'reference_type' => get_class($adjustment),
                        'reference_id' => $adjustment->id,
                        'user_id' => Auth::id(),
                        'notes' => $data['notes'] ?? "Adjustment from {$adjustment->adjustment_number}",
                    ]);

                    // ==========================================================
                    // LANGKAH 5: PICU PROSES FINANSIAL UNTUK KONSINYASI
                    // (Logika ini sudah benar)
                    // ==========================================================
                    if ($quantityChange < 0 && $inventory->location->ownership_type === 'consignment') {
                        event(new ConsignmentStockConsumed(
                            $inventory,
                            abs($quantityChange), // Jumlah yang hilang/rusak (nilai absolut)
                            $adjustment // Dokumen pemicunya adalah Inventory Adjustment ini
                        ));
                    }
                } // Akhir loop items

                return $adjustment;
            }); // Akhir DB Transaction

            Notification::make()
                ->title('Inventory Adjustment Posted Successfully')
                ->success()
                ->send();

            return $adjustment;

        } catch (ValidationException $e) {
            // Tangkap error validasi (misal stok tidak cukup)
            Notification::make()->title('Adjustment Failed')->body($e->getMessage())->danger()->send();
            $this->halt(); // Hentikan dan tetap di halaman create
            // ==========================================================
            // --- PERBAIKAN: Lemparkan kembali (re-throw) exception ---
            // ==========================================================
            throw $e;
        } catch (\Exception $e) {
            // Tangkap error umum lainnya
            Log::error("Inventory Adjustment Creation Failed: ". $e->getMessage() . "\n" . $e->getTraceAsString());
            Notification::make()->title('An Unexpected Error Occurred')->body($e->getMessage())->danger()->send();
            $this->halt();
            // ==========================================================
            // --- PERBAIKAN: Lemparkan kembali (re-throw) exception ---
            // ==========================================================
            throw $e;
        }

        // 'return null;' sudah dihapus, 'throw' sudah menangani exit path.
    }

    // Redirect ke halaman Edit/View setelah Create
    protected function getRedirectUrl(): string
    {
        // Arahkan ke halaman Edit (yang akan menampilkan Infolist)
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
