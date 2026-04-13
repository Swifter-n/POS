<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ShipmentRoute;
use App\Services\PricingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // $data sudah berisi:
        // - customer_id
        // - supplying_plant_id (BARU)
        // - terms_of_payment_id
        // - salesman_id
        // - customer_po_number
        // - order_date
        // - payment_type
        // - notes

        // 1. Tambahkan data default dari server
        $data['so_number'] = 'SO-' . date('Ym') . '-' . random_int(1000, 9999);
        $data['business_id'] = Auth::user()->business_id;
        $data['status'] = 'draft';

        // 2. Set nilai finansial awal ke 0.
        // (Ongkir akan dihitung di 'afterCreate')
        $data['sub_total'] = 0;
        $data['total_discount'] = 0;
        $data['tax'] = 0;
        $data['shipping_cost'] = 0; // Ongkir dihitung di afterCreate
        $data['grand_total'] = 0; // Total awal adalah 0

        return $data;
    }

    /**
     * Aksi yang dijalankan SETELAH record Sales Order (header) berhasil dibuat.
     * Tugasnya: Menghitung ongkos kirim awal dan mengupdate grand total.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        // ==========================================================
        // --- PERBAIKAN: Gunakan 'supplying_plant_id' dari SO ---
        // ==========================================================

        // 1. Ambil data yang diperlukan (Customer & Plant ID dari SO)
        $customer = Customer::find($record->customer_id);
        $sourcePlantId = $record->supplying_plant_id; // <-- Ambil dari SO
        $destinationAreaId = $customer?->area_id;

        $transportCost = 0;

        if ($customer && $destinationAreaId && $sourcePlantId) {

            $route = ShipmentRoute::where('source_plant_id', $sourcePlantId) // <-- Filter Plant Sumber
                                  ->whereHas('destinationAreas', function ($query) use ($destinationAreaId) {
                                        $query->where('areas.id', $destinationAreaId);
                                  })
                                  ->first();

            if ($route) {
                $transportCost = $route->base_cost ?? 0;
                // Cek surcharge
                $areaPivotRelation = $route->destinationAreas()->where('area_id', $destinationAreaId)->first();
                if ($areaPivotRelation) {
                    $transportCost += $areaPivotRelation->pivot->surcharge ?? 0;
                }
            } else {
                Log::warning("CreateSalesOrder: ShipmentRoute not found for Source Plant ID {$sourcePlantId} to Area ID {$destinationAreaId}.");
            }
        } else {
             Log::warning("CreateSalesOrder: Cannot calculate shipping cost. Missing Customer Area or SO Supplying Plant.");
        }
        // ==========================================================


        // Logika PPN (Tax) awal (karena subtotal 0, tax 0)
        $taxAmount = 0;

        // Grand total awal HANYA biaya kirim
        $grandTotal = $transportCost + $taxAmount; // (subtotal & diskon masih 0)

        // Update record SO dengan biaya kirim dan total awal
        $record->update([
            'shipping_cost' => $transportCost,
            'grand_total' => $grandTotal,
            'tax' => $taxAmount,
        ]);

        Log::info("SalesOrder {$record->so_number} created. Initial shipping cost: {$transportCost}, Initial Grand Total: {$grandTotal}");
    }
}
