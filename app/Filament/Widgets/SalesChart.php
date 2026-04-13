<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Outlet;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesChart extends ChartWidget
{
    protected static ?string $heading = 'POS Revenue (Last 7 Days)';
    protected static ?int $sort = 2; // Tampilkan di antara Stats dan Tabel

    protected function getData(): array
    {
        $user = Auth::user();

        // 1. Buat Query Awal (Sama seperti di Stats Widget)
        $query = Order::query();
        if ($user->hasRole('owner')) {
            $query->whereHas('outlet', fn(Builder $q) => $q->where('business_id', $user->business_id));
        } elseif ($user->locationable_type === Outlet::class) {
            $query->where('outlet_id', $user->locationable_id);
        } else {
            $query->whereRaw('0 = 1');
        }

        // 2. Ambil data 7 hari terakhir
        $data = $query
            ->whereBetween('created_at', [Carbon::today()->subDays(6), Carbon::today()->endOfDay()])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_price) as total'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // 3. Siapkan data untuk chart
        $labels = [];
        $values = [];
        $date = Carbon::today()->subDays(6);

        // Buat label untuk 7 hari (meskipun tidak ada data)
        for ($i = 0; $i < 7; $i++) {
            $dateString = $date->format('Y-m-d');
            $labels[] = $date->format('M d'); // Format "Nov 12"

            // Cari data untuk tanggal ini
            $dayData = $data->firstWhere('date', $dateString);
            $values[] = $dayData ? $dayData->total : 0; // Isi 0 jika tidak ada penjualan

            $date->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => $values,
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'borderColor' => 'rgb(54, 162, 235)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line'; // Tipe grafik
    }
}
