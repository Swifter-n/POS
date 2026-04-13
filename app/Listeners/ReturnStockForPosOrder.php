<?php

namespace App\Listeners;

use App\Events\PosOrderCancelled;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReturnStockForPosOrder implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Buat event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle event.
     * Logika ini akan mencari SEMUA pergerakan stok 'POS_CONSUMPTION'
     * yang terkait dengan order ini dan membalikkannya (increment).
     */
    public function handle(PosOrderCancelled $event): void
    {
        $order = $event->order;
        Log::info("[Listener] ReturnStockForPosOrder dipanggil untuk Order #{$order->order_number}.");

        try {
            DB::transaction(function () use ($order) {
                // 1. Cari semua pergerakan stok KELUAR (konsumsi) yang dibuat oleh order ini
                $consumptionMovements = InventoryMovement::where('reference_type', Order::class)
                    ->where('reference_id', $order->id)
                    ->where('type', 'POS_CONSUMPTION')
                    ->where('quantity_change', '<', 0) // Hanya ambil yang mengurangi stok
                    ->get();

                if ($consumptionMovements->isEmpty()) {
                    Log::warning("[Listener] Order #{$order->order_number} dibatalkan, tetapi tidak ada stok yang perlu dikembalikan (tidak ada 'POS_CONSUMPTION' movement).");
                    return;
                }

                // 2. Loop setiap pergerakan konsumsi dan kembalikan stoknya
                foreach ($consumptionMovements as $movement) {
                    $inventory = $movement->inventory; // Ambil batch/stok yang dulu dikurangi
                    if (!$inventory) {
                        Log::error("[Listener] Gagal mengembalikan stok: Inventory ID {$movement->inventory_id} tidak ditemukan.");
                        continue;
                    }

                    $quantityToReturn = abs($movement->quantity_change); // Ubah (misal) -18 menjadi +18

                    // 3. KEMBALIKAN (INCREMENT) stok ke batch aslinya
                    $inventory->increment('avail_stock', $quantityToReturn);

                    Log::info("[Listener] Stok dikembalikan: {$quantityToReturn} ke Inv ID {$inventory->id} (Produk: {$inventory->product_id})");

                    // 4. Buat pergerakan stok MASUK (reversal) sebagai jejak audit
                    InventoryMovement::create([
                        'inventory_id' => $inventory->id,
                        'quantity_change' => $quantityToReturn, // Positif
                        'stock_after_move' => $inventory->avail_stock, // Stok setelah ditambah
                        'type' => 'POS_CANCEL_RETURN', // Tipe baru untuk reversal
                        'reference_type' => get_class($order),
                        'reference_id' => $order->id,
                        'user_id' => $order->cashier_id, // Tetap gunakan kasir asli
                        'notes' => "Stock returned from cancelled POS Order #{$order->order_number}"
                    ]);
                }
            });

            Notification::make()->title('Stock Returned')
                ->body("Stock for cancelled order #{$order->order_number} has been successfully returned to inventory.")
                ->success()
                ->sendToDatabase($order->cashier); // Kirim notif ke kasir

        } catch (\Exception $e) {
            Log::error("Failed to return stock for Order #{$order->id}: " . $e->getMessage());
            Notification::make()->title('Critical Error')
                ->body("Stock return failed for order #{$order->id}: {$e->getMessage()}")
                ->danger()
                ->sendToDatabase(Auth::user());
        }
    }
}
