<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Inventory;  // <-- PERUBAHAN 1: Menggunakan model Inventory
use App\Models\Bom;
use Illuminate\Support\Facades\Log;

/**
 * PosInventoryService
 *
 * Bertanggung jawab untuk mengecek ketersediaan stok produk di outlet POS.
 * Didesain sebagai "Warning System", tidak memblokir penjualan.
 *
 * LOGIKA BARU (v4):
 * 1. Menggunakan model App\Models\Inventory.
 * 2. Menjumlahkan 'avail_stock' dari semua 'location' yang
 * terhubung ke 'outlet_id' via relasi polimorfik.
 */
class PosInventoryService
{
    /**
     * Ambang batas untuk status "Stok Menipis".
     * Anda bisa memindahkan ini ke database/config jika perlu.
     */
    private const LOW_STOCK_THRESHOLD = 10;

    // Mendefinisikan status sebagai konstanta agar konsisten
    private const STATUS_IN_STOCK = 'IN_STOCK';
    private const STATUS_LOW_STOCK = 'LOW_STOCK';
    private const STATUS_OUT_OF_STOCK = 'OUT_OF_STOCK';
    private const STATUS_RAW_MATERIAL_ISSUE = 'RAW_MATERIAL_ISSUE';

    /**
     * Method publik utama.
     * Mengecek ketersediaan produk di outlet.
     * Controller harus sudah melakukan eager load: ->with('bom.items.product')
     *
     * @param Product $product Produk yang akan dicek (harus sudah di-load relasinya)
     * @param Outlet $outlet Outlet tempat penjualan
     * @return array [status: string, displayMessage: string|null]
     */
    public static function getProductAvailability(Product $product, Outlet $outlet): array
    {
        // Pengaman jika controller lupa melakukan eager load
        $product->loadMissing('bom.items.product');

        // SCENARIO 1: Produk 'finished_good' TANPA BOM
        // (Contoh: Air Mineral, Roti Coklat yg dibeli jadi)
        // Cek jika relasi 'bom' (hasOne) itu null
        if (!$product->bom) {
            return self::checkFinishedGoodStock($product, $outlet);
        }

        // SCENARIO 2: Produk 'finished_good' DENGAN BOM
        // (Contoh: Kopi Susu, Kentang Goreng dari RM)
        // Delegasikan ke method checker BOM
        return self::checkBomStock($product, $outlet);
    }

    /**
     * Memeriksa stok untuk "Barang Jadi" (Finished Good) - Tanpa BOM.
     * Logika: Cek langsung kuantitas di tabel Inventory.
     */
    private static function checkFinishedGoodStock(Product $product, Outlet $outlet): array
    {
        // Logika query yang benar:
        // Sum 'avail_stock' dari SEMUA lokasi di dalam outlet ini
        // yang cocok dengan product_id.
        $quantity = Inventory::where('product_id', $product->id)
            ->whereHas('location', function ($query) use ($outlet) {
                $query->where('locatable_type', Outlet::class)
                      ->where('locatable_id', $outlet->id);
            })
            ->sum('avail_stock');

        // Terapkan logika "Warning System"
        if ($quantity <= 0) {
            return [
                'status' => self::STATUS_OUT_OF_STOCK,
                'displayMessage' => 'Stok tercatat 0. Harap periksa fisik.'
            ];
        }

        if ($quantity <= self::LOW_STOCK_THRESHOLD) {
            return [
                'status' => self::STATUS_LOW_STOCK,
                'displayMessage' => "Stok menipis (sisa $quantity). Harap periksa."
            ];
        }

        // Jika stok > LOW_STOCK_THRESHOLD
        return [
            'status' => self::STATUS_IN_STOCK,
            'displayMessage' => null
        ];
    }

    /**
     * Memeriksa stok untuk produk "Racikan" (Finished Good dengan BOM).
     * Logika: Cek kuantitas SEMUA bahan baku (raw material) yang
     * memiliki usage_type = 'RAW_MATERIAL_STORE'.
     *
     * === DIPERBARUI (V5) ===
     * Logika ini sekarang mengumpulkan SEMUA bahan baku yang kurang,
     * tidak berhenti pada kesalahan pertama.
     */
    private static function checkBomStock(Product $product, Outlet $outlet): array
    {
        // Kita sudah tahu $product->bom ada dari method getProductAvailability

        // 1. Filter item BOM: Kita HANYA peduli pada bahan baku
        //    yang dikonsumsi di outlet (Store).
        $storeBomItems = $product->bom->items->where('usage_type', 'RAW_MATERIAL_STORE');

        // 2. LOGIKA FALLBACK:
        //    Jika produk ini punya BOM, tapi TIDAK ADA item 'RAW_MATERIAL_STORE'
        //    (misal, resepnya hanya untuk Pabrik/Plant),
        //    maka perlakukan dia seperti 'finished_good' biasa.
        if ($storeBomItems->isEmpty()) {
            Log::info("Produk '{$product->name}' (ID: {$product->id}) memiliki BOM, tapi tidak ada item 'RAW_MATERIAL_STORE'. Cek stok FG.");
            return self::checkFinishedGoodStock($product, $outlet);
        }

        // === PERUBAHAN LOGIKA DIMULAI DI SINI ===

        // 3. Buat array untuk menampung bahan baku yang hilang
        $missingIngredients = [];

        // 4. Loop melalui setiap bahan baku yang dibutuhkan UNTUK STORE
        foreach ($storeBomItems as $item) {
            // $item adalah BomItem
            // Gunakan relasi 'product' (bukan 'rawMaterial')
            $rawMaterial = $item->product;
            $requiredQty = $item->quantity; // Kuantitas yg dibutuhkan (misal: 10gr)

            // Jika data resep tidak valid (bahan baku terhapus)
            if (!$rawMaterial) {
                Log::warning("BOM item (ID: {$item->id}) untuk '{$product->name}' (BOM ID: {$product->bom->id}) merujuk ke product_id yang tidak valid.");
                continue; // Lanjutkan ke item berikutnya
            }

            // 5. Cek stok bahan baku ini di outlet (SUM dari semua lokasi di outlet tsb)
            $ingredientStock = Inventory::where('product_id', $rawMaterial->id)
                ->whereHas('location', function ($query) use ($outlet) {
                    $query->where('locatable_type', Outlet::class)
                          ->where('locatable_id', $outlet->id);
                })
                ->sum('avail_stock');

            // 6. INI INTINYA:
            //    Jika stok bahan baku KURANG DARI yang dibutuhkan
            //    TAMBAHKAN ke array $missingIngredients. JANGAN langsung return.
            if ($ingredientStock < $requiredQty) {
                $missingIngredients[] = $rawMaterial->name;
            }
        }

        // 7. SETELAH loop selesai, periksa array $missingIngredients
        if (!empty($missingIngredients)) {
            // Ubah array nama bahan baku menjadi string yang dipisahkan koma
            $missingList = implode(', ', $missingIngredients);

            return [
                'status' => self::STATUS_RAW_MATERIAL_ISSUE,
                'displayMessage' => "Bahan baku ({$missingList}) mungkin tidak cukup."
            ];
        }

        // 8. Jika lolos dari loop DAN array $missingIngredients kosong
        //    (berarti semua bahan baku cukup)
        return [
            'status' => self::STATUS_IN_STOCK,
            'displayMessage' => null
        ];
    }
}
