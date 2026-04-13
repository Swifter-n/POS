<?php

namespace App\Filament\Resources\BomResource\Pages;

use App\Filament\Resources\BomResource;
use Filament\Actions;
use Filament\Resources\Pages\view;
use Filament\Resources\Pages\ViewRecord;

class ViewBom extends ViewRecord
{
    protected static string $resource = BomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
