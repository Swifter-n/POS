<?php

namespace App\Filament\Resources\PutawayRuleResource\Pages;

use App\Filament\Resources\PutawayRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPutawayRules extends ListRecords
{
    protected static string $resource = PutawayRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
