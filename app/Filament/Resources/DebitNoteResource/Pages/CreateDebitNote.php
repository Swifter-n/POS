<?php

namespace App\Filament\Resources\DebitNoteResource\Pages;

use App\Filament\Resources\DebitNoteResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDebitNote extends CreateRecord
{
    protected static string $resource = DebitNoteResource::class;

     protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['business_id'] = Auth::user()->business_id;
        $data['debit_note_number'] = 'DN-' . date('Ym') . '-' . random_int(1000, 9999);
        $data['status'] = 'open'; // Status awal

        // Kalkulasi ulang total
        $data['total_amount'] = collect($data['items'])->sum(fn($item) => (float)$item['quantity'] * (float)$item['price_per_item']);

        return $data;
    }
}
