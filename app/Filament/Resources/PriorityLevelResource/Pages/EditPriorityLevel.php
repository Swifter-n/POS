<?php

namespace App\Filament\Resources\PriorityLevelResource\Pages;

use App\Filament\Resources\PriorityLevelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPriorityLevel extends EditRecord
{
    protected static string $resource = PriorityLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
