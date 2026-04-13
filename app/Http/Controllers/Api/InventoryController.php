<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryController extends Controller
{
    /**
     * Helper untuk mendapatkan Outlet ID User
     */
    private function getOutletId($user)
    {
        if (!empty($user->outlet_id)) return $user->outlet_id;
        // Cek polimorfik locationable
        if ($user->locationable_type === 'App\\Models\\Outlet' && !empty($user->locationable_id)) {
            return $user->locationable_id;
        }
        return null;
    }

    /**
     * GET /api/v1/pos/inventory
     * Mengambil daftar stok produk di outlet ini (Read Only Mode).
     * Digunakan untuk fitur Cek Stok / Price Checker di POS.
     * * Note: Fungsi 'adjust' (Stock Opname) telah dipindahkan ke modul StockCount
     * untuk audit trail yang lebih baik.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        if (!$outletId) {
            return response()->json(['message' => 'User tidak terhubung dengan outlet manapun.'], 403);
        }

        // 1. Dapatkan ID Lokasi fisik milik Outlet ini
        // (Sistem WMS menyimpan stok di Location ID, bukan Outlet ID langsung)
        $locationIds = Location::where('locatable_type', 'App\\Models\\Outlet')
            ->where('locatable_id', $outletId)
            ->pluck('id')
            ->toArray();

        $search = $request->input('search');

        // 2. Query Produk
        $query = Product::where('business_id', $user->business_id)
            ->where('status', true)
            // Filter tipe produk yang relevan untuk dicek stoknya
            ->whereIn('product_type', ['finished_good', 'raw_material', 'merchandise']);

        if ($search) {
            $query->where(function($q) use ($search) {
                // Gunakan ilike untuk PostgreSQL (Case Insensitive)
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhere('barcode', 'ilike', "%{$search}%");
            });
        }

        $products = $query->orderBy('name')->paginate(20);

        // 3. Map dengan Total Stok dari tabel 'inventories'
        $data = $products->getCollection()->map(function ($product) use ($locationIds) {

            // Hitung Total Stok di semua lokasi outlet (Sum All Batches)
            // Menggunakan kolom 'avail_stock' sesuai schema WMS baru
            $qty = Inventory::where('product_id', $product->id)
                ->whereIn('location_id', $locationIds)
                ->sum('avail_stock');

            $qty = (float) $qty;

            // Tentukan Status Stok Visual
            $status = 'IN_STOCK';
            $alertQty = $product->alert_quantity ?? 5;

            if ($qty <= 0.0001) {
                $status = 'OUT_OF_STOCK';
            } elseif ($qty <= $alertQty) {
                $status = 'LOW_STOCK';
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'image' => $product->thumbnail ?? $product->image, // Handle variasi nama field
                'unit' => $product->base_uom ?? 'Pcs',
                'current_stock' => $qty, // Stok Realtime Akumulatif
                'status' => $status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ]
        ]);
    }
}
