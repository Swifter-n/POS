<?php

namespace App\Filament\Resources\InventoryMoveResource\Pages;

use App\Filament\Resources\InventoryMoveResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;

class ViewInventoryMove extends ViewRecord
{
    protected static string $resource = InventoryMoveResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Movement Details')
                    ->schema([
                        TextEntry::make('move_number'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('plant.name'),
                        TextEntry::make('warehouse.name'),
                        TextEntry::make('reason'),
                        TextEntry::make('movedBy.name')->label('Moved By'),
                        TextEntry::make('moved_at')->dateTime(),
                    ])->columns(2),
                Section::make('Item & Location Details')
                    ->schema([
                        TextEntry::make('product.name'),
                        TextEntry::make('inventory.batch')->label('Batch'),
                        TextEntry::make('inventory.sled')->label('SLED')->date(),
                        TextEntry::make('quantity_base')->label('Quantity (Base UoM)'),
                        TextEntry::make('sourceLocation.name')->label('From Location'),
                        TextEntry::make('destinationLocation.name')->label('To Location'),
                    ])->columns(2),
            ]);
    }
}
