<?php

namespace App\Filament\Resources\ProductionOrderResource\Pages;

use App\Filament\Resources\ProductionOrderResource;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProductionOrder extends CreateRecord
{
    protected static string $resource = ProductionOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
{
    $user = Auth::user();

    // --- MULAI LOGIKA KONVERSI UOM ---
    $product = Product::find($data['finished_good_id']);
    $plannedUomName = $data['planned_uom'];
    $plannedQty = (float)$data['quantity_planned'];

    $uom = $product->uoms()->where('uom_name', $plannedUomName)->first();
    $conversionRate = $uom?->conversion_rate ?? 1;

    // Konversi ke Base UoM dan TIMPA nilainya
    $data['quantity_planned'] = $plannedQty * $conversionRate;
    // --- AKHIR LOGIKA KONVERSI UOM ---

    // Tambahkan data server-side
    $data['business_id'] = $user->business_id;
    $data['created_by_user_id'] = $user->id;
    $data['production_order_number'] = 'PRO-' . date('Ym') . '-' . random_int(1000, 9999);
    $data['status'] = 'draft';

    return $data;
}

    // Redirect ke halaman Edit setelah Create
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

}
