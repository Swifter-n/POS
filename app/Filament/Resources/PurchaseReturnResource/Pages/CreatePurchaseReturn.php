<?php

namespace App\Filament\Resources\PurchaseReturnResource\Pages;

use App\Filament\Resources\PurchaseReturnResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseReturn extends CreateRecord
{
    protected static string $resource = PurchaseReturnResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Data dari form:
        // $data['plant_id'], $data['warehouse_id'],
        // $data['supplier_id'], $data['purchase_order_id'] (opsional)
        // $data['notes']

        $data['business_id'] = Auth::user()->business_id;
        $data['created_by_user_id'] = Auth::id();
        $data['return_number'] = 'PRT-' . date('Ym') . '-' . random_int(1000, 9999); // Awalan PRT
        $data['status'] = 'draft'; // Status Awal

        return $data;
    }

    // HAPUS 'handleRecordCreation' (Logika dipindah ke Edit/RelationManager)

    /**
     * Arahkan ke halaman Edit setelah header dibuat,
     * agar user bisa menambahkan item via Relation Manager.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
