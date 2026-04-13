<?php

namespace App\Filament\Resources\SupplierInvoiceResource\Pages;

use App\Filament\Resources\SupplierInvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupplierInvoices extends ListRecords
{
    protected static string $resource = SupplierInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
