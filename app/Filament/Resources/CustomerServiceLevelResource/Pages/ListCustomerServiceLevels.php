<?php

namespace App\Filament\Resources\CustomerServiceLevelResource\Pages;

use App\Filament\Resources\CustomerServiceLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerServiceLevels extends ListRecords
{
    protected static string $resource = CustomerServiceLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
