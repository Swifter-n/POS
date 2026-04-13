<?php

namespace App\Filament\Resources\DebitNoteResource\Pages;

use App\Filament\Resources\DebitNoteResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDebitNote extends EditRecord
{
    protected static string $resource = DebitNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('markAsApplied')
                ->label('Mark as Applied')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn ($record) => $record->status === 'open')
                ->action(function ($record) {
                    // Di sini Anda bisa menambahkan logika integrasi dengan modul pembayaran hutang (AP)
                    // Contoh: $record->applyToPayment(...);

                    $record->update(['status' => 'applied']);
                    Notification::make()->title('Debit Note has been applied.')->success()->send();
                }),
            //Actions\DeleteAction::make(),
        ];
    }
}
