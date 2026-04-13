<?php

namespace App\Providers;

// --- Impor Event & Listener (POS) ---
use App\Events\PosOrderPaid;
use App\Listeners\DeductStockForPosOrder;

// --- Impor Event & Listener (Purchase Return) ---
use App\Events\PurchaseReturnCompleted;
use App\Listeners\CreateDebitNoteFromPurchaseReturn;

// --- Impor Event & Listener (Consignment) ---
use App\Events\ConsignmentStockConsumed;
use App\Events\PosOrderCancelled;
use App\Listeners\CreateInvoiceFromConsignment;
use App\Listeners\ReturnStockForPosOrder;
// --- Impor Observer ---
use App\Models\Order;
use App\Observers\OrderObserver;

// --- PERBAIKAN: Gunakan namespace EventServiceProvider yang benar ---
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// --- Tambahan 'use' untuk event 'Registered' Anda ---
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Support\Facades\Event;

// --- PERBAIKAN: Pastikan 'extends ServiceProvider' ---
class EventServiceProvider extends ServiceProvider
{
    /**
     * Mapping Event ke Listener untuk aplikasi Anda.
     */
    protected $listen = [
        // Mapping untuk POS (Baru)
        PosOrderPaid::class => [
            DeductStockForPosOrder::class,
        ],

        PosOrderCancelled::class => [
            ReturnStockForPosOrder::class,
        ],

        // Mapping untuk Purchase Return (Sudah Ada)
        PurchaseReturnCompleted::class => [
            CreateDebitNoteFromPurchaseReturn::class,
        ],

        // Mapping untuk Consignment (Sudah Ada)
        ConsignmentStockConsumed::class => [
            CreateInvoiceFromConsignment::class,
        ],

        // Event default Laravel (dari kode Anda)
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Model observer untuk aplikasi Anda.
     */
    protected $observers = [
        // Daftarkan OrderObserver untuk 'mendengarkan' model Order
        Order::class => [OrderObserver::class],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Tentukan apakah event dan listener harus ditemukan secara otomatis.
     */
    public function shouldDiscoverEvents(): bool
    {
        // false sudah benar karena kita mendaftarkan secara manual
        return false;
    }
}
