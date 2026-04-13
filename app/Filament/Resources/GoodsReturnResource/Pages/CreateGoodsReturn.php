<?php

namespace App\Filament\Resources\GoodsReturnResource\Pages;

use App\Filament\Resources\GoodsReturnResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateGoodsReturn extends CreateRecord
{
    protected static string $resource = GoodsReturnResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // (Logika ini diambil dari Model Anda, tapi lebih aman di sini)
        $data['business_id'] = Auth::user()->business_id;
        $data['created_by_user_id'] = Auth::id();
        $data['requested_by_user_id'] = Auth::id(); // <-- [PERBAIKAN] Isi field NOT NULL
        $data['return_number'] = 'GRT-' . date('Ym') . '-' . random_int(1000, 9999);
        $data['status'] = 'draft'; // Status Awal

        // Hapus 'items' dari $data (jika ada)
        unset($data['items']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Arahkan ke halaman 'edit' dari record yang baru dibuat
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
