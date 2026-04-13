<?php

namespace App\Filament\Resources\TermsOfPaymentResource\Pages;

use App\Filament\Resources\TermsOfPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTermsOfPayments extends ListRecords
{
    protected static string $resource = TermsOfPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
