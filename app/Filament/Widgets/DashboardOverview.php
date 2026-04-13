<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\SalesOrder;
use App\Models\StockCount;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardOverview extends BaseWidget
{
    protected static ?int $sort = 1; // Tampilkan di paling atas

    protected function getStats(): array
    {
        $user = Auth::user();
        $businessId = $user->business_id;

        // ==========================================================
        // 1. BUAT QUERY DASAR (BASE QUERY) DENGAN FILTER PERMISSION
        // ==========================================================

        // Filter untuk data POS (Order)
        $posQuery = Order::query();
        // Filter untuk data B2B (SalesOrder)
        $soQuery = SalesOrder::query();
        // Filter untuk data Stock Count
        $scQuery = StockCount::query();

        if ($user->hasRole('owner')) {
            // Owner melihat semua data di dalam business_id mereka
            $posQuery->whereHas('outlet', fn(Builder $q) => $q->where('business_id', $businessId));
            $soQuery->where('business_id', $businessId);
            $scQuery->where('business_id', $businessId);

        } elseif ($user->locationable_type === Outlet::class) {
            // Staff Outlet hanya melihat data POS dari outlet mereka
            $posQuery->where('outlet_id', $user->locationable_id);
            // Staff Outlet tidak bisa melihat SO atau Stock Count
            $soQuery->whereRaw('0 = 1'); // Paksa query gagal
            $scQuery->whereRaw('0 = 1'); // Paksa query gagal

        } else {
            // Jika user tidak terhubung ke mana pun, jangan tampilkan data
            $posQuery->whereRaw('0 = 1');
            $soQuery->whereRaw('0 = 1');
            $scQuery->whereRaw('0 = 1');
        }

        // ==========================================================
        // 2. HITUNG DATA HARI INI (BERDASARKAN QUERY YANG SUDAH DIFILTER)
        // ==========================================================

        // --- DATA POS ---
        $revenueToday = (clone $posQuery)->whereDate('created_at', Carbon::today())->sum('total_price');
        $ordersToday = (clone $posQuery)->whereDate('created_at', Carbon::today())->count();

        // Data 7 hari untuk chart kecil
        $revenue7Days = (clone $posQuery)
    ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_price) as total'))
    ->whereBetween('created_at', [Carbon::today()->subDays(6), Carbon::today()->endOfDay()])
    ->groupBy('date')
    ->orderBy('date', 'asc')
    ->get()
    ->pluck('total')
    ->toArray();

        // --- DATA B2B (SALES ORDER) ---
        $pendingSOs = (clone $soQuery)
            ->whereIn('status', ['pending', 'approved']) // Status "terbuka"
            ->count();

        // --- DATA INVENTARIS (STOCK COUNT) ---
        $pendingSCs = (clone $scQuery)
            ->whereIn('status', ['pending_approval', 'pending_validation'])
            ->count();


        // ==========================================================
        // 3. BUAT KARTU STATS
        // ==========================================================
        return [
            Stat::make('Today\'s POS Revenue', 'Rp ' . number_format($revenueToday, 0, ',', '.'))
                ->description('Revenue from POS Orders')
                ->color('success')
                ->chart($revenue7Days) // Chart 7 hari
                ->icon('heroicon-o-currency-dollar'),

            Stat::make('Today\'s POS Orders', $ordersToday)
                ->description('Total POS orders received')
                ->color('info')
                ->icon('heroicon-o-shopping-bag'),

            // Kartu ini hanya muncul untuk Owner/Manajer
            Stat::make('Pending Sales Orders (B2B)', $pendingSOs)
                ->description('Sales Orders awaiting processing')
                ->color('warning')
                ->icon('heroicon-o-truck'),
                //->visible($user->hasRole('owner')), // <-- Dinamis

            // Kartu ini hanya muncul untuk Owner/Manajer
            Stat::make('Stock Counts to Approve', $pendingSCs)
                ->description('Stock Counts awaiting validation/posting')
                ->color('danger')
                ->icon('heroicon-o-clipboard-document-check')
                //->visible($user->hasRole('owner')), // <-- Dinamis
        ];
    }
}
