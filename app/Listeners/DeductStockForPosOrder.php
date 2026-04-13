<?php

namespace App\Listeners;

use App\Events\PosOrderPaid;
use App\Models\Bom;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Zone;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue; // <-- Penting untuk performa
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class DeductStockForPosOrder implements ShouldQueue
{
    use InteractsWithQueue;

    // ========================================
    // QUEUE CONFIGURATION
    // ========================================
    public $tries = 3;              // Retry 3x jika gagal
    public $timeout = 120;          // Timeout 2 menit
    public $maxExceptions = 3;
    public $backoff = [5, 10, 30];  // Delay antar retry: 5s, 10s, 30s

    public function __construct()
    {
        //
    }

    public function handle(PosOrderPaid $event): void
    {
        $order = $event->order;

        try {
            DB::transaction(function () use ($order) {
                // Load relasi yang dibutuhkan
                $order->load(
                    'items.product.uoms',
                    'items.product.bom.items.product.uoms',
                    'outlet.locations',
                    'cashier'
                );

                if (!$order->outlet) {
                    Log::error("[Queue] Order {$order->order_number} tidak terhubung ke Outlet.");
                    return;
                }

                // Cari Lokasi Stok MAIN
                $mainZoneId = Zone::where('code', 'MAIN')->value('id');
                if (!$mainZoneId) {
                    Log::error("[Queue] Zona 'MAIN' tidak ditemukan.");
                    $this->sendNotification($order->cashier, 'Konfigurasi Error', "Zona MAIN tidak ditemukan di sistem.", 'danger');
                    return;
                }

                $stockLocation = $order->outlet->locations
                    ->where('zone_id', $mainZoneId)
                    ->where('status', true)
                    ->first();

                if (!$stockLocation) {
                    Log::error("[Queue] Outlet {$order->outlet->name} tidak memiliki lokasi MAIN.");
                    $this->sendNotification($order->cashier, 'Konfigurasi Error', "Outlet tidak memiliki lokasi stok MAIN.", 'warning');
                    return;
                }

                $stockLocationId = $stockLocation->id;
                Log::info("[Queue] Processing stock consumption for Order {$order->order_number}");

                // Kumpulkan semua komponen yang harus dikonsumsi
                $consumptionList = new Collection();

                foreach ($order->items as $orderItem) {
                    $productSold = $orderItem->product;
                    if (!$productSold) continue;

                    // Konversi UoM ke Base UoM
                    $quantitySold = (float) $orderItem->quantity;
                    $uomSoldName = $orderItem->uom ?? $productSold->base_uom;
                    $uomData = $productSold->uoms->where('uom_name', $uomSoldName)->first();
                    $conversionRate = $uomData?->conversion_rate ?? 1.0;
                    $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate;
                    $quantitySoldInBaseUom = $quantitySold * $conversionRate;

                    // Cek apakah produk punya BOM
                    $posBomItems = $productSold->bom?->items->where('usage_type', 'raw_material_store');

                    if ($posBomItems && $posBomItems->isNotEmpty()) {
                        // CASE 1: Produk Rakitan (punya BOM)
                        Log::info("[Queue] Product '{$productSold->name}' has BOM. Consuming components...");

                        foreach ($posBomItems as $bomItem) {
                            $componentProduct = $bomItem->product;
                            if (!$componentProduct) continue;

                            $bomQty = (float) $bomItem->quantity;
                            $bomUomName = $bomItem->uom;
                            $bomUomData = $componentProduct->uoms->where('uom_name', $bomUomName)->first();
                            $bomConversionRate = $bomUomData?->conversion_rate ?? 1.0;
                            $qtyNeededPerUnit_Base = $bomQty * $bomConversionRate;
                            $totalQtyNeeded = $qtyNeededPerUnit_Base * $quantitySoldInBaseUom;

                            $consumptionList->push([
                                'product_id' => $componentProduct->id,
                                'product_name' => $componentProduct->name,
                                'quantity' => $totalQtyNeeded,
                                'base_uom' => $componentProduct->base_uom,
                            ]);
                        }
                    } else {
                        // CASE 2: Produk Jadi (tanpa BOM)
                        Log::info("[Queue] Product '{$productSold->name}' direct consumption.");

                        $consumptionList->push([
                            'product_id' => $productSold->id,
                            'product_name' => $productSold->name,
                            'quantity' => $quantitySoldInBaseUom,
                            'base_uom' => $productSold->base_uom,
                        ]);
                    }
                }

                if ($consumptionList->isEmpty()) {
                    Log::info("[Queue] No components to consume for Order #{$order->order_number}.");
                    return;
                }

                // Group by product_id
                $groupedConsumption = $consumptionList->groupBy('product_id')
                    ->map(fn($group) => [
                        'product_id' => $group->first()['product_id'],
                        'product_name' => $group->first()['product_name'],
                        'base_uom' => $group->first()['base_uom'],
                        'quantity' => $group->sum('quantity'),
                    ]);

                // ========================================
                // KONSUMSI STOK (FEFO + Pessimistic Lock)
                // ========================================
                $stockWarnings = [];

                foreach ($groupedConsumption as $itemToConsume) {
                    $productId = $itemToConsume['product_id'];
                    $totalQtyNeeded = (float) $itemToConsume['quantity'];

                    Log::info("[Queue] Consuming {$totalQtyNeeded} {$itemToConsume['base_uom']} of {$itemToConsume['product_name']}");

                    // Lock inventory rows (prevent race condition)
                    $inventories = Inventory::where('location_id', $stockLocationId)
                        ->where('product_id', $productId)
                        ->where('avail_stock', '>', 0)
                        ->orderBy('sled', 'asc') // FEFO
                        ->lockForUpdate() // Pessimistic Lock
                        ->get();

                    $availableStock = $inventories->sum('avail_stock');

                    // ========================================
                    // HANDLING STOK TIDAK CUKUP
                    // ========================================
                    if ($availableStock < $totalQtyNeeded) {
                        $deficit = $totalQtyNeeded - $availableStock;

                        Log::warning("[Queue] INSUFFICIENT STOCK: {$itemToConsume['product_name']}. Needed: {$totalQtyNeeded}, Available: {$availableStock}, Deficit: {$deficit}");

                        $stockWarnings[] = sprintf(
                            "⚠️ %s: Kurang %.2f %s (Butuh: %.2f, Tersedia: %.2f)",
                            $itemToConsume['product_name'],
                            $deficit,
                            $itemToConsume['base_uom'],
                            $totalQtyNeeded,
                            $availableStock
                        );

                        // TETAP KONSUMSI STOK YANG ADA (MINUS DIPERBOLEHKAN)
                        // Atau bisa skip konsumsi, tergantung business rule
                        // Di sini kita konsumsi sampai habis, sisanya jadi "hutang stok"
                    }

                    // Konsumsi stok dengan FEFO
                    $remainingToConsume = $totalQtyNeeded;

                    foreach ($inventories as $inventory) {
                        if ($remainingToConsume <= 0) break;

                        $qtyFromThisBatch = min($remainingToConsume, $inventory->avail_stock);

                        // Decrement stok (bisa jadi minus jika stok tidak cukup)
                        $inventory->decrement('avail_stock', $qtyFromThisBatch);

                        InventoryMovement::create([
                            'inventory_id' => $inventory->id,
                            'quantity_change' => -$qtyFromThisBatch,
                            'stock_after_move' => $inventory->avail_stock,
                            'type' => 'POS_CONSUMPTION',
                            'reference_type' => get_class($order),
                            'reference_id' => $order->id,
                            'user_id' => $order->cashier_id,
                            'notes' => "POS Order #{$order->order_number}"
                        ]);

                        $remainingToConsume -= $qtyFromThisBatch;
                    }

                    // Jika masih ada sisa (stok habis tapi butuh lebih), catat sebagai negative stock
                    if ($remainingToConsume > 0) {
                        Log::warning("[Queue] Creating NEGATIVE STOCK record for {$itemToConsume['product_name']}: -{$remainingToConsume}");

                        // OPTIONAL: Buat inventory record dengan stok minus
                        // atau bisa skip jika tidak ingin tracking negative stock
                    }

                    Log::info("[Queue] Successfully processed {$itemToConsume['product_name']}");
                }

                // ========================================
                // KIRIM NOTIFIKASI JIKA ADA WARNING
                // ========================================
                if (!empty($stockWarnings)) {
                    $warningMessage = "Order #{$order->order_number} berhasil diproses, namun:\n\n" . implode("\n", $stockWarnings);

                    $this->sendNotification(
                        $order->cashier,
                        '⚠️ Stok Tidak Mencukupi',
                        $warningMessage,
                        'warning'
                    );

                    // OPTIONAL: Kirim notifikasi ke Admin/Manager juga
                    // $this->notifyAdmins($order, $stockWarnings);
                }

                Log::info("[Queue] Stock consumption completed for Order {$order->order_number}");
            });

        } catch (\Exception $e) {
            Log::error("[Queue] FAILED to deduct stock for Order #{$order->id}: " . $e->getMessage());

            // Kirim notifikasi error
            $this->sendNotification(
                $order->cashier,
                'Error Konsumsi Stok',
                "Order #{$order->order_number} gagal memotong stok. Error: {$e->getMessage()}",
                'danger'
            );

            // Re-throw untuk trigger retry
            throw $e;
        }
    }

    /**
     * Helper: Kirim Notifikasi ke User
     */
    private function sendNotification($user, string $title, string $body, string $type = 'info')
    {
        if (!$user) return;

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match($type) {
            'danger' => $notification->danger(),
            'warning' => $notification->warning(),
            'success' => $notification->success(),
            default => $notification->info(),
        };

        $notification->sendToDatabase($user);
    }

    /**
     * Handle job failure (setelah 3x retry gagal)
     */
    public function failed(PosOrderPaid $event, \Throwable $exception)
    {
        $order = $event->order;

        Log::critical("[Queue] PERMANENT FAILURE for Order #{$order->id}: " . $exception->getMessage());

        // Update order notes
        $order->update([
            'notes' => ($order->notes ?? '') . "\n[STOCK ERROR] " . $exception->getMessage()
        ]);

        // Kirim alert ke admin
        $this->sendNotification(
            $order->cashier,
            'Critical: Stock Consumption Failed',
            "Order #{$order->order_number} gagal memotong stok setelah 3x percobaan. Silakan cek manual.",
            'danger'
        );
    }
}

// class DeductStockForPosOrder implements ShouldQueue
// {
//     use InteractsWithQueue;

//     /**
//      * Buat event listener.
//      */
//     public function __construct()
//     {
//         //
//     }

//     /**
//      * Handle event.
//      * Ini adalah logika yang dipindah dari OrderObserver
//      */
//     public function handle(PosOrderPaid $event): void
//     {
//         $order = $event->order;

//         // Gunakan DB Transaction untuk keamanan
//         try {
//             DB::transaction(function () use ($order) {
//                 // ==========================================================
//                 // --- PERBAIKAN LOGIKA 'LOAD' ---
//                 // Muat semua relasi yang dibutuhkan secara efisien
//                 // ==========================================================
//                 $order->load(
//                     'items.product.uoms', // 1. UoM dari produk yg DIJUAL (misal: pcs, crt)
//                     'items.product.bom.items.product.uoms', // 2. UoM dari produk KOMPONEN (misal: gram, kg)
//                     'outlet.locations', // 3. Lokasi di outlet
//                     'cashier' // 4. Kasir
//                 );

//                 if (!$order->outlet) {
//                     Log::error("POS Order {$order->order_number} (ID: {$order->id}) tidak terhubung ke Outlet. Konsumsi stok dibatalkan.");
//                     return;
//                 }

//                 // Cari Lokasi Stok Utama di Outlet (Asumsi Zona 'MAIN')
//                 $mainZoneId = Zone::where('code', 'MAIN')->value('id');
//                 if (!$mainZoneId) {
//                      Log::error("Zona 'MAIN' tidak ditemukan. Konsumsi stok dibatalkan.");
//                      Notification::make()->title('Stock Error')
//                         ->body("Konfigurasi Zona 'MAIN' tidak ditemukan.")
//                         ->danger()->sendToDatabase($order->cashier);
//                      return;
//                 }

//                 $stockLocation = $order->outlet->locations
//                                     ->where('zone_id', $mainZoneId)
//                                     ->where('status', true)
//                                     ->first();

//                 if (!$stockLocation) {
//                     Log::error("Outlet {$order->outlet->name} (ID: {$order->outlet_id}) tidak memiliki Lokasi 'MAIN' yang aktif. Konsumsi stok dibatalkan.");
//                     Notification::make()->title('Stock Warning')
//                         ->body("Stock consumption failed: Outlet '{$order->outlet->name}' has no MAIN stock location configured.")
//                         ->warning()->sendToDatabase($order->cashier);
//                     return;
//                 }
//                 $stockLocationId = $stockLocation->id;

//                 Log::info("[Listener] Processing stock consumption for Order {$order->order_number} from Location ID {$stockLocationId}");

//                 // Kumpulkan semua komponen yang harus dikonsumsi
//                 $consumptionList = new Collection();

//                 // Loop 1: Baca BOM dari semua item yang terjual
//                 foreach ($order->items as $orderItem) {
//                     $productSold = $orderItem->product;
//                     if (!$productSold) continue;

//                     // ==========================================================
//                     // --- (LANGKAH 4) LOGIKA KONVERSI UOM ---
//                     // ==========================================================
//                     $quantitySold = (float) $orderItem->quantity;
//                     $uomSoldName = $orderItem->uom ?? $productSold->base_uom; // Ambil UoM dari item, fallback ke base_uom

//                     // Cari conversion rate dari UoM yang dijual
//                     $uomData = $productSold->uoms->where('uom_name', $uomSoldName)->first();
//                     $conversionRate = $uomData?->conversion_rate ?? 1.0;
//                     $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate; // Hindari pembagian nol

//                     // Ini adalah kuantitas dalam Satuan Dasar (misal: 2 CRT * 24 = 48 PCS)
//                     $quantitySoldInBaseUom = $quantitySold * $conversionRate;
//                     // ==========================================================


//                     // Cek apakah produk ini punya BOM
//                     $posBomItems = $productSold->bom?->items->where('usage_type', 'raw_material_store');

//                     // KASUS 1: Jual "Kopi Susu" (Produk Rakitan)
//                     if ($posBomItems && $posBomItems->isNotEmpty()) {

//                         Log::info("[Listener] Case 1 (BOM): '{$productSold->name}' qty {$quantitySoldInBaseUom} (Base). Mengonsumsi resep...");

//                         foreach ($posBomItems as $bomItem) {
//                             $componentProduct = $bomItem->product;
//                             if (!$componentProduct) continue;

//                             // Konversi Qty BOM ke Base UoM komponen itu sendiri
//                             $bomQty = (float) $bomItem->quantity;
//                             $bomUomName = $bomItem->uom;
//                             $bomUomData = $componentProduct->uoms->where('uom_name', $bomUomName)->first();
//                             $bomConversionRate = $bomUomData?->conversion_rate ?? 1.0;
//                             $qtyNeededPerUnit_Base = $bomQty * $bomConversionRate; // Qty resep dlm Base UoM

//                             // Total kebutuhan = (Qty Resep Base) * (Qty Jual Base)
//                             $totalQtyNeeded = $qtyNeededPerUnit_Base * $quantitySoldInBaseUom;

//                             $consumptionList->push([
//                                 'product_id' => $componentProduct->id,
//                                 'product_name' => $componentProduct->name,
//                                 'quantity' => $totalQtyNeeded,
//                                 'base_uom' => $componentProduct->base_uom,
//                             ]);
//                         }

//                     // KASUS 2: Jual "Bubuk Kopi Bag" (Produk Jadi)
//                     } else {
//                         Log::info("[Listener] Case 2 (Direct): '{$productSold->name}'. Mengonsumsi produk itu sendiri.");

//                         $consumptionList->push([
//                             'product_id' => $productSold->id,
//                             'product_name' => $productSold->name,
//                             'quantity' => $quantitySoldInBaseUom, // Gunakan Qty Base UoM yg sudah dikonversi
//                             'base_uom' => $productSold->base_uom,
//                         ]);
//                     }
//                 }

//                 if ($consumptionList->isEmpty()) {
//                     Log::info("[Listener] No components found to consume for Order #{$order->order_number}.");
//                     return;
//                 }

//                 // Gabungkan (Group by)
//                 $groupedConsumption = $consumptionList->groupBy('product_id')
//                                     ->map(fn($group) => [
//                                         'product_id' => $group->first()['product_id'],
//                                         'product_name' => $group->first()['product_name'],
//                                         'base_uom' => $group->first()['base_uom'],
//                                         'quantity' => $group->sum('quantity'),
//                                     ]);

//                 // PROSES KONSUMSI STOK (FEFO)
//                 foreach ($groupedConsumption as $itemToConsume) {
//                     $productId = $itemToConsume['product_id'];
//                     $totalQtyNeeded = (float) $itemToConsume['quantity'];

//                     Log::info("[Listener] Consuming {$totalQtyNeeded} {$itemToConsume['base_uom']} of {$itemToConsume['product_name']}...");

//                     $inventories = Inventory::where('location_id', $stockLocationId)
//                                         ->where('product_id', $productId)
//                                         ->where('avail_stock', '>', 0)
//                                         ->orderBy('sled', 'asc') // FEFO
//                                         ->get();

//                     if ($inventories->sum('avail_stock') < $totalQtyNeeded) {
//                         Log::error("[Listener] Stock Consumption FAILED for Product ID {$productId} ({$itemToConsume['product_name']}). Required: {$totalQtyNeeded}, Available: {$inventories->sum('avail_stock')}.");
//                         Notification::make()->title('Stock Consumption Failed')
//                             ->body("Insufficient stock for component '{$itemToConsume['product_name']}' at outlet. Inventory needs adjustment.")
//                             ->danger()->sendToDatabase($order->cashier);
//                         continue;
//                     }

//                     $remainingToConsume = $totalQtyNeeded;
//                     foreach ($inventories as $inventory) {
//                         if ($remainingToConsume <= 0) break;
//                         $qtyFromThisBatch = min($remainingToConsume, $inventory->avail_stock);
//                         $inventory->decrement('avail_stock', $qtyFromThisBatch);

//                         InventoryMovement::create([
//                             'inventory_id' => $inventory->id,
//                             'quantity_change' => -$qtyFromThisBatch,
//                             'stock_after_move' => $inventory->avail_stock,
//                             'type' => 'POS_CONSUMPTION',
//                             'reference_type' => get_class($order),
//                             'reference_id' => $order->id,
//                             'user_id' => $order->cashier_id,
//                             'notes' => "Consumed for POS Order #{$order->order_number}"
//                         ]);
//                         $remainingToConsume -= $qtyFromThisBatch;
//                     }
//                     Log::info("[Listener] Successfully consumed {$totalQtyNeeded} of {$itemToConsume['product_name']}.");
//                 }
//             });
//         } catch (\Exception $e) {
//             Log::error("Failed to deduct stock for Order #{$order->id}: " . $e->getMessage());
//             Notification::make()->title('Critical Error')
//                 ->body("Stock consumption failed with error: {$e->getMessage()}")
//                 ->danger()->sendToDatabase(Auth::user()); // Kirim ke user yg login
//         }
//     }
// }
