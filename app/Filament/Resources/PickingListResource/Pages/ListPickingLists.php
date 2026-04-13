<?php

namespace App\Filament\Resources\PickingListResource\Pages;

use App\Filament\Resources\PickingListResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPickingLists extends ListRecords
{
    protected static string $resource = PickingListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
