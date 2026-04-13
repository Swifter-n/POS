<?php

namespace App\Filament\Widgets;

use App\Models\Inventory;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryStatsWidget extends BaseWidget
{

    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        return $user->hasRole($roleName); // Asumsi Spatie
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $businessId = $user->business_id;
        $isOwner = self::userHasRole('Owner');

        // Ambil lokasi user (jika ada)
        $userPlantId = null;
        $userOutletId = null;
        $userWarehouseId = null;
        $user->loadMissing('locationable');

        if ($user->locationable_type === Warehouse::class) {
            $userWarehouseId = $user->locationable_id;
            $userPlantId = $user->locationable?->plant_id;
        } elseif ($user->locationable_type === Outlet::class) {
            $userOutletId = $user->locationable_id;
        }

        // ==========================================================
        // STAT 1: Total Nilai Inventaris (DIPERBARUI)
        // (Meminjam logika query dari InventoryResource)
        // ==========================================================

        // ==========================================================
        // --- PERBAIKAN: Tentukan nama tabel secara eksplisit ---
        // ==========================================================
        $inventoryQuery = Inventory::query()
            ->where('inventories.business_id', $businessId)
            ->where('inventories.avail_stock', '>', 0);

        if (!$isOwner) {
            // Filter berdasarkan lokasi user
            $inventoryQuery->where(function (Builder $q) use ($userPlantId, $userOutletId) {
                $hasFilter = false;
                if ($userPlantId) {
                    $hasFilter = true;
                    // Ambil stok dari semua lokasi (WH/Outlet) di dalam Plant user
                    $q->whereHas('location', function (Builder $locQuery) use ($userPlantId) {
                        $locQuery->where(function (Builder $subQ) use ($userPlantId) {
                            $subQ->where(fn($whQ) => $whQ->where('locatable_type', Warehouse::class)->whereHasMorph('locatable', [Warehouse::class], fn($wh) => $wh->where('plant_id', $userPlantId)))
                                 ->orWhere(fn($otQ) => $otQ->where('locatable_type', Outlet::class)->whereHasMorph('locatable', [Outlet::class], fn($ot) => $ot->where('supplying_plant_id', $userPlantId)));
                        });
                    });
                }
                if ($userOutletId) {
                    $hasFilter = true;
                    // Tambahkan stok dari outlet spesifik user (jika dia staf outlet)
                    $q->orWhereHas('location', function (Builder $locQuery) use ($userOutletId) {
                         $locQuery->where('locatable_type', Outlet::class)
                                  ->where('locatable_id', $userOutletId);
                    });
                }
                if (!$hasFilter) {
                    $q->whereRaw('0 = 1');
                }
            });
        }

        // Lakukan join & sum HANYA setelah query permission selesai
        $totalValue = (clone $inventoryQuery)
            ->join('products', 'inventories.product_id', '=', 'products.id')
            ->sum(DB::raw('inventories.avail_stock * products.cost'));

        // ==========================================================
        // STAT 2: Stock Count Menunggu Persetujuan (DIPERBARUI)
        // (Meminjam logika query dari StockCountResource)
        // ==========================================================
        $pendingCountsQuery = StockCount::where('business_id', $businessId)
            ->whereIn('status', ['pending_approval', 'pending_validation']);

        if (!$isOwner) {
            // Filter SC berdasarkan lokasi user
            $pendingCountsQuery->where(function (Builder $q) use ($userPlantId, $userOutletId) {
                if ($userPlantId) {
                    $q->whereHasMorph('countable', [Warehouse::class], fn (Builder $whQuery) => $whQuery->where('plant_id', $userPlantId));
                }
                if ($userOutletId) {
                    $q->orWhereHasMorph('countable', [Outlet::class], fn (Builder $otQuery) => $otQuery->where('id', $userOutletId));
                }
                if (!$userPlantId && !$userOutletId) {
                     $q->whereRaw('0 = 1');
                }
            });
        }
        $pendingCounts = $pendingCountsQuery->count();

        // ==========================================================
        // STAT 3: Transfer Masuk (Menunggu Diterima)
        // (Logika Anda sudah benar, kita gunakan lagi)
        // ==========================================================
        $inboundTransfers = 0;
        if ($user->locationable) {
            $query = Shipment::where('status', 'shipping')
                            ->where('business_id', $businessId);

            $query->where(function (Builder $q) use ($userPlantId, $userOutletId) {
                $hasFilter = false;
                if ($userPlantId) {
                    $hasFilter = true;
                    $q->where('destination_plant_id', $userPlantId);
                }
                if ($userOutletId) {
                    $hasFilter = true;
                    $q->orWhere('destination_outlet_id', $userOutletId);
                }
                if (!$hasFilter) {
                    $q->whereRaw('0 = 1');
                }
            });
            $query->where(function (Builder $q) {
                 $q->whereNotNull('destination_plant_id')
                   ->orWhereNotNull('destination_outlet_id');
            });

            $inboundTransfers = $query->count();
        }

        // ==========================================================
        // STAT 4: PO Menunggu Diterima (Goods Receipt) (DIPERBARUI)
        // (Meminjam logika query dari PurchaseOrderResource)
        // ==========================================================
        $pendingPOsQuery = PurchaseOrder::where('business_id', $businessId)
            ->whereIn('status', ['approved', 'partially_received']);

        if (!$isOwner) {
            // Jika bukan Owner, filter berdasarkan plant user
            if ($userWarehouseId && $userPlantId) {
                $pendingPOsQuery->where('plant_id', $userPlantId);
            } else {
                // Jika user adalah Staf Outlet, mereka tidak bisa terima PO
                $pendingPOsQuery->whereRaw('0 = 1');
            }
        }
        $pendingPOs = $pendingPOsQuery->count();

        // ==========================================================
        // Tampilkan Stats
        // ==========================================================

        $stats = [
            Stat::make('Total Inventory Value', 'Rp ' . number_format($totalValue))
                ->description('Total cost value of available stock')
                ->color('success'),

            Stat::make('PO Pending Receipt', $pendingPOs)
                ->description('Purchase Orders ready for Goods Receipt')
                ->color('info'),
                //->visible($isOwner || $userWarehouseId), // <-- Sembunyikan dari Staf Outlet

            Stat::make('Pending Stock Counts', $pendingCounts)
                ->description('Stock Counts awaiting validation/approval')
                ->color($pendingCounts > 0 ? 'warning' : 'success'),
        ];

        // Hanya tampilkan stat "Inbound" jika user adalah staf gudang/outlet (Owner tidak perlu lihat ini)
        if (!$isOwner && $user->locationable) {
            $stats[] = Stat::make('Inbound Transfers (In Transit)', $inboundTransfers)
                ->description('Shipments on the way to your location')
                ->color('info');
        }

        return $stats;
    }
}
