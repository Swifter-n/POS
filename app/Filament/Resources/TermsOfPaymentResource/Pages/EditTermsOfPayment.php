<?php

namespace App\Filament\Resources\TermsOfPaymentResource\Pages;

use App\Filament\Resources\TermsOfPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTermsOfPayment extends EditRecord
{
    protected static string $resource = TermsOfPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
