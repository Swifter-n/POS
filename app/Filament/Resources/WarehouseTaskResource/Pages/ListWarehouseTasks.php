<?php

namespace App\Filament\Resources\WarehouseTaskResource\Pages;

use App\Filament\Resources\WarehouseTaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWarehouseTasks extends ListRecords
{
    protected static string $resource = WarehouseTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
