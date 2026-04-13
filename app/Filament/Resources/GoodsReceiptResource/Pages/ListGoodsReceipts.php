<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGoodsReceipts extends ListRecords
{
    protected static string $resource = GoodsReceiptResource::class;

protected function getHeaderActions(): array
    {
        // Kita tidak menyediakan tombol "Create" karena Goods Receipt
        // selalu dibuat dari Purchase Order.
        return [];
    }

}
