<?php

namespace App\Filament\Resources\ShipmentRouteResource\Pages;

use App\Filament\Resources\ShipmentRouteResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\view;
use Filament\Resources\Pages\ViewRecord;

class ViewShipmentRoute extends ViewRecord
{
    protected static string $resource = ShipmentRouteResource::class;

    /**
     * Menampilkan detail utama (read-only) dari Shipment Route.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Route Details')
                    ->schema([
                        TextEntry::make('sourceWarehouse.name')
                            ->label('Source Warehouse'),
                        TextEntry::make('base_cost')
                            ->money('IDR'),
                        TextEntry::make('notes')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    /**
     * Tambahkan tombol "Edit" di sini agar Anda bisa
     * dengan mudah berpindah dari mode "View" ke "Edit".
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
