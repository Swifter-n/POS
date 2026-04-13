<?php

namespace App\Filament\Resources\CustomerServiceLevelResource\Pages;

use App\Filament\Resources\CustomerServiceLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomerServiceLevel extends EditRecord
{
    protected static string $resource = CustomerServiceLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
