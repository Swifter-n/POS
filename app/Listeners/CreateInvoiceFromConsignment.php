<?php

namespace App\Listeners;

use App\Events\ConsignmentStockConsumed;
use App\Models\SupplierInvoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Listener ini berjalan di background (implements ShouldQueue).
 * Tugasnya adalah membuat tagihan hutang (Supplier Invoice) secara otomatis
 * setiap kali stok konsinyasi dikonsumsi.
 */
class CreateInvoiceFromConsignment implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ConsignmentStockConsumed $event): void
    {
        try {
            DB::transaction(function () use ($event) {
                $inventory = $event->inventory;
                $consumedQuantity = $event->consumedQuantity;
                $triggeringRecord = $event->triggeringRecord;

                // Eager load relasi yang dibutuhkan
                $inventory->load('product', 'location.supplier');
                $product = $inventory->product;
                $supplier = $inventory->location->supplier;

                // 1. Validasi: Pastikan lokasi konsinyasi memiliki supplier
                if (!$supplier) {
                    Log::error("Consignment stock consumed from a location without a supplier. Inventory ID: {$inventory->id}");
                    return;
                }

                // 2. Tentukan harga beli. Prioritaskan harga 'cost' di master produk.
                $purchasePrice = $product->cost ?? 0;
                if ($purchasePrice <= 0) {
                    Log::error("Product {$product->name} (ID: {$product->id}) has no purchase cost defined. Cannot create supplier invoice.");
                    return;
                }

                // 3. Hitung total nilai
                $totalAmount = $purchasePrice * $consumedQuantity;

                // 4. Buat Supplier Invoice baru
                $supplierInvoice = SupplierInvoice::create([
                    'invoice_number' => 'CONS-' . uniqid(),
                    'business_id' => $inventory->business_id,
                    'supplier_id' => $supplier->id,
                    'sourceable_id' => $triggeringRecord->id,
                    'sourceable_type' => get_class($triggeringRecord),
                    'invoice_date' => now(),
                    'total_amount' => $totalAmount,
                    'status' => 'unpaid',
                ]);

                // 5. Buat detail item untuk invoice tersebut
                $supplierInvoice->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $consumedQuantity,
                    'price_per_item' => $purchasePrice,
                    'total_price' => $totalAmount,
                ]);

                Log::info("Supplier Invoice #{$supplierInvoice->invoice_number} created for {$consumedQuantity} units of {$product->name} consumed from consignment.");
            });
        } catch (\Exception $e) {
            Log::critical("Failed to create supplier invoice from consignment consumption: " . $e->getMessage());
        }
    }
}
