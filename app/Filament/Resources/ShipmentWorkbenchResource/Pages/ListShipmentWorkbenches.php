<?php

namespace App\Filament\Resources\ShipmentWorkbenchResource\Pages;

use App\Filament\Resources\ShipmentWorkbenchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListShipmentWorkbenches extends ListRecords
{
    protected static string $resource = ShipmentWorkbenchResource::class;

    // protected function getHeaderActions(): array
    // {
    //     return [
    //         Actions\CreateAction::make(),
    //     ];
    // }

}
