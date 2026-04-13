<?php

namespace App\Filament\Resources\ShipmentRouteResource\Pages;

use App\Filament\Resources\ShipmentRouteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShipmentRoute extends EditRecord
{
    protected static string $resource = ShipmentRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }


}
