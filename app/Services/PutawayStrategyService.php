<?php

namespace App\Services;

use App\Models\Location;
use App\Models\PutawayRule;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PutawayStrategyService
{
    /**
     * ALGORITMA UTAMA: Mencari Bin Terbaik
     */
    public function findOptimalBin(Product $product, int $warehouseId): ?Location
    {
        // PRIORITAS 1: DIRECT ASSIGNMENT (Settingan di Produk)
        if ($product->target_zone_id) {
            Log::info("Putaway: Product '{$product->name}' has direct target Zone ID: {$product->target_zone_id}");

            // Cari bin kosong di zona tersebut
            $bin = $this->findAvailableBinInZone($product->target_zone_id, $warehouseId);

            if ($bin) {
                return $bin; // Ketemu!
            }
            Log::warning("Putaway: Target Zone {$product->target_zone_id} is FULL. Falling back to Rules...");
        }

        // PRIORITAS 2: RULE BASED (via Tabel putaway_rules)
        // Cari aturan berdasarkan Storage Condition atau Kategori
        $rules = $this->getApplicableRules($product, 'putaway');

        foreach ($rules as $rule) {
            // Hindari mengecek zona yang sama dua kali (jika tadi sudah dicek di Prioritas 1)
            if ($product->target_zone_id == $rule->target_zone_id) continue;

            $strategy = $rule->strategy; // 'empty_bin' atau 'mixed'
            $bin = $this->findAvailableBinInZone($rule->target_zone_id, $warehouseId, $strategy);

            if ($bin) {
                return $bin;
            }
        }

        return null; // Tidak ditemukan bin yang sesuai
        // PRIORITAS 3: GENERAL FALLBACK (Cari di Zone 'GEN')
        // Log::info("Putaway: No specific rules matched or zones full. Trying General Zone.");
        // return $this->findGeneralFallbackBin($warehouseId);
    }

    /**
     * Helper: Mengambil Rules yang cocok dengan Produk
     */
    private function getApplicableRules(Product $product, string $activityType): Collection
    {
        return PutawayRule::query()
            ->where('business_id', $product->business_id)

            // --- LOGIKA FLAGGING BARU ---
            ->whereIn('activity', [$activityType, 'both'])
            // ----------------------------

            ->where(function($q) use ($product) {
                // Match by Storage Condition
                if ($product->storage_condition) {
                    $q->orWhere('criteria_storage_condition', $product->storage_condition);
                }
                // Match by Product Type
                if ($product->product_type) {
                    $q->orWhere('criteria_product_type', $product->product_type);
                }
                // Match by Category
                if ($product->category_id) {
                    $q->orWhere('category_id', $product->category_id);
                }
                // Match Global (Null criteria)
                $q->orWhere(function($sub) {
                    $sub->whereNull('criteria_storage_condition')
                        ->whereNull('criteria_product_type')
                        ->whereNull('category_id');
                });
            })
            ->orderBy('priority', 'asc')
            ->get();
    }
//     private function getApplicableRules(Product $product): Collection
// {
//     return PutawayRule::query()
//         ->where('business_id', $product->business_id)
//         ->where(function($query) use ($product) {
//             // LOGIKA: Rule dianggap cocok jika Kriteria-nya NULL (Wildcard) ATAU sama dengan Produk

//             // 1. Cek Storage Condition (e.g. FAST_MOVING)
//             $query->where(function($q) use ($product) {
//                 $q->whereNull('criteria_storage_condition')
//                   ->orWhere('criteria_storage_condition', $product->storage_condition);
//             });

//             // 2. Cek Business Type (e.g. finished_good)
//             $query->where(function($q) use ($product) {
//                 $q->whereNull('criteria_product_type')
//                   ->orWhere('criteria_product_type', $product->product_type);
//             });

//             // 3. Cek Category
//             $query->where(function($q) use ($product) {
//                 $q->whereNull('category_id')
//                   ->orWhere('category_id', $product->category_id);
//             });
//         })
//         // Sorting Prioritas:
//         // User tetap bisa set manual (1, 2, 3).
//         // Tapi jika ingin otomatis "Spesifik menang lawan General", kita bisa order by non-null count di database level (advanced),
//         // Untuk sekarang, kita percayakan pada 'priority' manual admin agar kontrol penuh.
//         ->orderBy('priority', 'asc')
//         ->get();
// }
    // private function getApplicableRules(Product $product): Collection
    // {
    //     return PutawayRule::query()
    //         ->where('business_id', $product->business_id)
    //         ->where(function($q) use ($product) {
    //             // Match by Storage Condition (e.g. COLD, DRY) - Kolom string di products
    //             if ($product->storage_condition) {
    //                 $q->where('product_type', $product->storage_condition);
    //             }
    //             // Match by Category
    //             if ($product->category_id) {
    //                 $q->orWhere('category_id', $product->category_id);
    //             }
    //         })
    //         ->orderBy('priority', 'asc')
    //         ->get();
    // }

    /**
     * Helper: Query Fisik ke Tabel Location (Cek Kapasitas)
     */
    private function findAvailableBinInZone(int $zoneId, int $warehouseId, string $strategy = 'empty_bin'): ?Location
    {
        $query = Location::where('zone_id', $zoneId)
            ->where('locatable_type', Warehouse::class)
            ->where('locatable_id', $warehouseId)
            ->where('status', true)
            ->where('is_sellable', true) // Asumsi is_sellable = Storage aktif

            // === UPDATE BARU: STRICT TYPE FILTER ===
            // Pastikan hanya mengambil level terbawah (Bin/Posisi Pallet)
            // Jangan mengambil Rack/Area/Row
            ->whereIn('type', ['BIN', 'PALLET', 'RACK_SLOT'])
            // Sesuaikan string ini dengan enum di migration Anda
            // =======================================

            // Validasi Kapasitas
            ->whereRaw('current_pallets < max_pallets');

        // Strategi Pengisian
        if ($strategy === 'empty_bin') {
            // Prioritaskan bin yang benar-benar kosong (0 pallet)
            $query->orderBy('current_pallets', 'asc');
        } else {
            // Mixed strategy: isi yang setengah penuh dulu (optimasi ruang)
            $query->orderBy('current_pallets', 'desc');
        }

        // Pathfinding: Urutkan berdasarkan sequence jalan picker
        // Ini memastikan picker mengisi dari Bin 01 -> Bin 02 -> dst
        $query->orderBy('picking_sequence', 'asc')
              ->orderBy('name', 'asc');

        return $query->first();
    }

    // private function findGeneralFallbackBin(int $warehouseId): ?Location
    // {
    //     // Cari sembarang bin kosong di Zone 'GEN' atau 'MAIN'
    //     return Location::whereHas('zone', fn($q) => $q->whereIn('code', ['GEN']))
    //         ->where('locatable_type', Warehouse::class)
    //         ->where('locatable_id', $warehouseId)
    //         ->where('status', true)
    //         ->where('is_sellable', true)
    //         ->whereRaw('current_pallets < max_pallets')
    //         ->orderBy('picking_sequence', 'asc')
    //         ->orderBy('name', 'asc')
    //         ->first();
    // }

    public function getPickingZonePriorities(Product $product): array
    {
        // 1. Cek Direct Assignment di Produk (Prioritas Tertinggi)
        $zoneIds = [];

        // 1. Cek Direct Assignment (Opsional, mungkin picking tidak butuh ini, tapi kita biarkan dulu)
        if ($product->target_zone_id) {
            $zoneIds[] = $product->target_zone_id;
        }

        // 2. FILTER: Hanya ambil rule Picking atau Both
        $rules = $this->getApplicableRules($product, 'picking');

        foreach ($rules as $rule) {
            if (!in_array($rule->target_zone_id, $zoneIds)) {
                $zoneIds[] = $rule->target_zone_id;
            }
        }

        // 3. Fallback Default (General Zones)
        // Jika tidak ada rule, atau setelah rule habis, cari di General
        // $generalZones = Zone::whereIsn('code', ['GEN', 'BULK'])
        //                     ->where('business_id', $product->business_id)
        //                     ->pluck('id')
        //                     ->toArray();

        // foreach ($generalZones as $genId) {
        //     if (!in_array($genId, $zoneIds)) {
        //         $zoneIds[] = $genId;
        //     }
        // }

        return $zoneIds;
    }

}
