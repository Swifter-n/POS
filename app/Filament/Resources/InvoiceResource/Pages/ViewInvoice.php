<?php
namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markAsPaid')
                ->label('Mark as Paid')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->status === 'unpaid')
                ->action(function ($record) {
                    DB::transaction(function () use ($record) {
                        // 1. Update status invoice
                        $record->update(['status' => 'paid', 'paid_at' => now()]);

                        // 2. Kurangi saldo piutang customer
                        if ($record->salesOrder->payment_type === 'credit') {
                            $record->customer->decrement('current_balance', $record->grand_total);
                        }
                    });
                    Notification::make()->title('Invoice marked as paid!')->success()->send();
                }),

            Actions\Action::make('print')
                ->label('Print Invoice')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn ($record) => route('invoices.print', $record), shouldOpenInNewTab: true),
        ];
    }
}
