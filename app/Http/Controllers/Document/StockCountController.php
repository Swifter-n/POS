<?php

namespace App\Http\Controllers\Document;

use App\Http\Controllers\Controller;
use App\Models\StockCount;
use Barryvdh\DomPDF\Facade\Pdf; // Asumsi Anda sudah install barryvdh/laravel-dompdf
use Illuminate\Support\Facades\Log;

class StockCountController extends Controller
{
    /**
     * Membuat dan menampilkan file PDF Kertas Kerja Pemeriksaan (KKP).
     */
    public function printKKP(StockCount $stockCount)
    {
       try {
            // 1. Muat relasi header
            $stockCount->load([
                'countable', // Warehouse atau Outlet
                'plant',     // Plant (BARU)
                'zone',      // Zone (BARU, bisa null jika all zones)
            ]);

            // 2. Muat item (snapshot) beserta relasi yang diperlukan untuk sorting
            $items = $stockCount->items()->with([
                'product:id,sku,name,base_uom', // Info produk
                // Info lokasi lengkap untuk sorting & display
                'inventory.location' => function($query) {
                    $query->with('zone:id,code,name') // Muat Zona dari Lokasi
                          ->with('supplier:id,name'); // Muat Supplier (jika konsinyasi)
                }
            ])->get(); // Ambil koleksi

            // ==========================================================
            // --- KUNCI: Urutkan item berdasarkan "Walking Path" ---
            // ==========================================================
            // Urutkan berdasarkan: 1. Kode Zona, 2. Kode Lokasi, 3. Nama Produk
            $sortedItems = $items->sortBy(function ($item) {
                $location = $item->inventory?->location;
                if (!$location) return 'ZZZZ'; // Taruh yg error di akhir

                $zoneCode = $location->zone?->code ?? 'NOZONE';
                $locationCode = $location->code ?? 'NOLOC';
                $productName = $item->product?->name ?? 'NOPROD';

                // Format: [KODE_ZONA].[KODE_LOKASI].[NAMA_PRODUK]
                // Contoh: "FG.A1-R01-B1.Kopi" atau "RCV.RCV-01.Gula"
                return $zoneCode . '.' . $locationCode . '.' . $productName;
            });
            // ==========================================================

            $data = [
                'stockCount' => $stockCount,
                'items' => $sortedItems, // <-- Kirim item yang sudah diurutkan
            ];

            $pdf = Pdf::loadView('documents.stock-count-kkp', $data);

            return $pdf->stream('KKP-' . $stockCount->count_number . '.pdf');

       } catch (\Exception $e) {
             Log::error("Failed to print KKP {$stockCount->id}: " . $e->getMessage());
             abort(500, "Could not generate PDF: " . $e->getMessage());
       }
    }
}
