<?php

namespace App\Filament\Resources\InventoryMoveResource\Pages;

use App\Filament\Resources\InventoryMoveResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInventoryMove extends EditRecord
{
    protected static string $resource = InventoryMoveResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
