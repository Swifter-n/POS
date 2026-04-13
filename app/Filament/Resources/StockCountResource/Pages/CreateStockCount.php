<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

   protected function mutateFormDataBeforeCreate(array $data): array
    {
        // $data sudah berisi:
        // - plant_id (dari form)
        // - countable_type (dari form)
        // - countable_id (dari form)
        // - zone_id (dari form, bisa null)
        // - notes (dari form, bisa null)

        $user = Auth::user();

        // 1. Tambahkan data default dari server
        $data['business_id'] = $user->business_id;
        $data['created_by_user_id'] = $user->id;

        // 2. Set status default
        $data['status'] = 'draft';

        // 3. Generate count number jika belum ada (sesuai kode Anda)
        $data['count_number'] = 'SC-' . date('Ym') . '-' . random_int(1000, 9999);

        // Kembalikan semua data (termasuk plant_id, zone_id, dll. dari form) untuk disimpan
        return $data;
    }
}
