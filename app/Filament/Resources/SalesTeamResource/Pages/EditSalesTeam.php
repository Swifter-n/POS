<?php

namespace App\Filament\Resources\SalesTeamResource\Pages;

use App\Filament\Resources\SalesTeamResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesTeam extends EditRecord
{
    protected static string $resource = SalesTeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
