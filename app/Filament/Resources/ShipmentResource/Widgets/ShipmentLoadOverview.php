<?php

namespace App\Filament\Resources\ShipmentResource\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class ShipmentLoadOverview extends BaseWidget
{
    // Properti ini akan secara otomatis diisi dengan record Shipment saat widget dimuat di halaman Edit
    public ?Model $record = null;

    //protected static string $view = 'filament.resources.shipment-resource.widgets.shipment-load-overview';

    protected function getStats(): array
    {
        if (!$this->record) {
            return [];
        }

        // Eager load relasi untuk performa yang lebih baik (menghindari N+1 query)
        $this->record->loadMissing(['items.product', 'fleets']);

        // 1. Hitung total muatan dari item (dalam Base UoM)
        // Logikanya sama, hanya dibuat lebih ringkas dengan collection sum()
        $totalWeight = $this->record->items->sum(function ($item) {
            return ($item->product->weight_kg ?? 0) * $item->quantity;
        });

        $totalVolume = $this->record->items->sum(function ($item) {
            return ($item->product->volume_cbm ?? 0) * $item->quantity;
        });

        // 2. Hitung total kapasitas dari semua armada yang ditugaskan
        $totalMaxWeight = $this->record->fleets->sum('max_load_kg');
        $totalMaxVolume = $this->record->fleets->sum('max_volume_cbm');
        $fleetCount = $this->record->fleets->count();

        // 3. Hitung persentase utilisasi
        $weightPercent = ($totalMaxWeight > 0) ? round(($totalWeight / $totalMaxWeight) * 100) : 0;
        $volumePercent = ($totalMaxVolume > 0) ? round(($totalVolume / $totalMaxVolume) * 100) : 0;

        // 4. Kembalikan data Stat untuk ditampilkan
        return [
            Stat::make('Total Muatan Berat', number_format($totalWeight, 2) . " / " . number_format($totalMaxWeight, 2) . " KG")
                ->description("Utilisasi: {$weightPercent}%")
                ->descriptionIcon($weightPercent > 100 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-chart-bar')
                ->color($weightPercent > 100 ? 'danger' : ($weightPercent > 90 ? 'warning' : 'success')),

            Stat::make('Total Muatan Volume', number_format($totalVolume, 2) . " / " . number_format($totalMaxVolume, 2) . " CBM")
                ->description("Utilisasi: {$volumePercent}%")
                ->descriptionIcon($volumePercent > 100 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-chart-bar')
                ->color($volumePercent > 100 ? 'danger' : ($volumePercent > 90 ? 'warning' : 'success')),

            Stat::make('Jumlah Armada Ditugaskan', $fleetCount)
                ->description('Total kendaraan yang akan berangkat.')
                ->descriptionIcon('heroicon-m-truck'),
        ];
    }
}
