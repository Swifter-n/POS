<?php

namespace App\Filament\Resources\SalesTeamResource\Pages;

use App\Filament\Resources\SalesTeamResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSalesTeams extends ListRecords
{
    protected static string $resource = SalesTeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
