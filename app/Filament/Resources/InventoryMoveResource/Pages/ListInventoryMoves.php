<?php

namespace App\Filament\Resources\InventoryMoveResource\Pages;

use App\Filament\Resources\InventoryMoveResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryMoves extends ListRecords
{
    protected static string $resource = InventoryMoveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
