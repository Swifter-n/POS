<?php

namespace App\Filament\Resources\DiscountRuleResource\Pages;

use App\Filament\Resources\DiscountRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDiscountRule extends EditRecord
{
    protected static string $resource = DiscountRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
