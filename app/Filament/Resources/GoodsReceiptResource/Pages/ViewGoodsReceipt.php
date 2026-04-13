<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Tombol untuk kembali ke halaman daftar
            Actions\Action::make('back')
                ->label('Back to List')
                ->color('gray')
                ->url(static::getResource()::getUrl('index')),

            // Tombol Edit, bisa diaktifkan jika Anda membuat halaman EditGoodsReceipt
            // Actions\EditAction::make(),
        ];
    }
}
