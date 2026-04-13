<?php

namespace App\Filament\Resources\PurchasingGroupResource\Pages;

use App\Filament\Resources\PurchasingGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPurchasingGroup extends EditRecord
{
    protected static string $resource = PurchasingGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
