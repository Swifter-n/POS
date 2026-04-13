<?php

namespace App\Filament\Resources\PurchasingGroupResource\Pages;

use App\Filament\Resources\PurchasingGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPurchasingGroups extends ListRecords
{
    protected static string $resource = PurchasingGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
