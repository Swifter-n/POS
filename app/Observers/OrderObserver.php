<?php

namespace App\Observers;

use App\Events\PosOrderCancelled;
use App\Events\PosOrderPaid;
use App\Models\Order;
use App\Models\Bom; // Pastikan Anda mengimpor Bom
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Zone;
use App\Models\DiscountRule; // Diperlukan untuk applyDiscountRules
use App\Services\DiscountService; // Diperlukan untuk applyDiscountRules
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection; // Diperlukan untuk Collection
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     */
    public function created(Order $order): void
    {
        // Jika order baru dibuat dan statusnya SUDAH 'paid'
        // (misal: Take Away / Online)
        if (strtolower($order->status) === 'paid') {
            Log::info("OrderObserver 'created': Order #{$order->order_number} is 'paid'. Dispatching PosOrderPaid event.");
            PosOrderPaid::dispatch($order);
        }
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        // ==========================================================
        // --- LOGIKA UPDATE (DIPERBARUI) ---
        // ==========================================================

        // 1. Cek jika status berubah menjadi 'paid' (dari 'pending')
        if ($order->wasChanged('status') && strtolower($order->status) === 'paid') {
            Log::info("OrderObserver 'updated': Order #{$order->order_number} status changed to 'paid'. Dispatching PosOrderPaid event.");
            PosOrderPaid::dispatch($order);

        // 2. Cek jika status berubah menjadi 'cancelled'
        } elseif ($order->wasChanged('status') && strtolower($order->status) === 'cancelled') {

            // 3. Cek apakah status SEBELUMNYA adalah 'paid'
            $originalStatus = strtolower($order->getOriginal('status'));
            if ($originalStatus === 'paid') {
                Log::info("OrderObserver 'updated': Order #{$order->order_number} status changed from 'paid' to 'cancelled'. Dispatching PosOrderCancelled event.");
                // HANYA jika sudah 'paid', kita tembak event untuk kembalikan stok
                PosOrderCancelled::dispatch($order);
            } else {
                Log::info("OrderObserver 'updated': Order #{$order->order_number} status changed from '{$originalStatus}' to 'cancelled'. No stock return needed.");
            }
        }
    }
}
