<?php

namespace App\Filament\Resources\SupplierInvoiceResource\Pages;

use App\Filament\Resources\SupplierInvoiceResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierInvoice extends ViewRecord
{
    protected static string $resource = SupplierInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markAsPaid')
                ->label('Mark as Paid')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->status === 'unpaid')
                ->action(function ($record) {
                    $record->update(['status' => 'paid']);
                    Notification::make()->title('Invoice marked as paid!')->success()->send();
                }),
        ];
    }
}
