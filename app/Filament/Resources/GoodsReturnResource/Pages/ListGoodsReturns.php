<?php

namespace App\Filament\Resources\GoodsReturnResource\Pages;

use App\Filament\Resources\GoodsReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGoodsReturns extends ListRecords
{
    protected static string $resource = GoodsReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
