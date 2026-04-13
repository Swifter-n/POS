<?php

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductionPrintController extends Controller
{
    public function printPo(ProductionOrder $productionOrder)
    {
        try {
            // 1. Eager load relasi dasar
            $productionOrder->load([
                'plant', // Muat Plant tempat produksi
                'finishedGood', // Muat Produk Jadi
                // 'warehouse' DIHAPUS DARI SINI
                'finishedGood.bom.items.product.uoms',
            ]);

            // 1B. Muat Warehouse secara manual
            $warehouse = Warehouse::find($productionOrder->warehouse_id);

            // 2. Cari Picking List (PERBAIKAN: Gunakan 'pickingList' singular)
            $pickingList = $productionOrder->pickingList() // <-- UBAH KE SINGULAR
                ->whereNotIn('status', ['cancelled'])
                ->first();

            // 3. Jika Picking List ada, muat detailnya
            if ($pickingList) {
                $pickingList->load([
                    'user',
                    'items.product',
                    'items.sources.inventory.location.zone',
                ]);
            }

            // 4. Siapkan data untuk dikirim ke View
            $data = [
                'productionOrder' => $productionOrder,
                'pickingList' => $pickingList,
                'bomItems' => $productionOrder->finishedGood?->bom?->items,
                'warehouse' => $warehouse, // <-- Kirim data warehouse ke view
            ];

            // 5. Load View PDF
            $pdf = Pdf::loadView('documents.production_order_print', $data);

            return $pdf->stream('PROD-' . $productionOrder->production_order_number . '.pdf');

        } catch (\Exception $e) {
             Log::error("Failed to print Production Order {$productionOrder->id}: " . $e->getMessage());
             abort(500, "Could not generate PDF: " . $e->getMessage());
        }
    }
}
