<?php

namespace App\Filament\Resources\PriorityLevelResource\Pages;

use App\Filament\Resources\PriorityLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPriorityLevels extends ListRecords
{
    protected static string $resource = PriorityLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
