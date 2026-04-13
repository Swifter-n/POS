<?php

namespace App\Listeners;

use App\Events\PurchaseReturnCompleted;
use App\Models\DebitNote;
use App\Models\PurchaseOrderItem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreateDebitNoteFromPurchaseReturn implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(PurchaseReturnCompleted $event): void
    {
        $purchaseReturn = $event->purchaseReturn;

        // Hindari pembuatan Debit Note ganda
        if (DebitNote::where('purchase_return_id', $purchaseReturn->id)->exists()) {
            Log::warning("Debit Note for Purchase Return #{$purchaseReturn->return_number} already exists.");
            return;
        }

        $grandTotal = 0;
        $itemsToCreate = [];

        // Hitung total nilai dari item yang diretur
        foreach ($purchaseReturn->items as $returnItem) {
            $pricePerItem = 0;
            // Prioritas 1: Cari harga dari Purchase Order asal
            if ($purchaseReturn->purchase_order_id) {
                $poItem = PurchaseOrderItem::where('purchase_order_id', $purchaseReturn->purchase_order_id)
                    ->where('product_id', $returnItem->product_id)
                    ->first();
                $pricePerItem = $poItem?->price_per_item ?? 0;
            }

            // Prioritas 2: Fallback ke harga 'cost' di master produk
            if ($pricePerItem == 0) {
                $pricePerItem = $returnItem->product->cost ?? 0;
            }

            $totalPrice = $pricePerItem * $returnItem->quantity;
            $grandTotal += $totalPrice;

            $itemsToCreate[] = [
                'product_id' => $returnItem->product_id,
                'quantity' => $returnItem->quantity,
                'price_per_item' => $pricePerItem,
                'total_price' => $totalPrice,
                'reason' => $returnItem->reason,
            ];
        }

        // Buat Debit Note baru
        $debitNote = DebitNote::create([
            'debit_note_number' => 'DN-' . $purchaseReturn->return_number,
            'purchase_return_id' => $purchaseReturn->id,
            'supplier_id' => $purchaseReturn->supplier_id,
            'business_id' => $purchaseReturn->business_id,
            'note_date' => now(),
            'total_amount' => $grandTotal,
            'status' => 'open', // Status awal: open, applied, cancelled
        ]);

        // Simpan item-itemnya
        $debitNote->items()->createMany($itemsToCreate);

        Log::info("Debit Note #{$debitNote->debit_note_number} created successfully from Purchase Return #{$purchaseReturn->return_number}.");
    }
}
