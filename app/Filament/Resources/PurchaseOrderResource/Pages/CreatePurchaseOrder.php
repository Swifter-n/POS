<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Models\BusinessSetting;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        // 1. Set data default
        $data['business_id'] = $user->business_id;
        $data['created_by_user_id'] = $user->id;
        $data['status'] = 'draft';

        // 2. Generate PO Number
        $data['po_number'] = 'PO-' . date('Ym') . '-' . random_int(1000, 9999);

        // 3. Initialize Financials
        // shipping_cost should be passed from the form state (dehydrated)
        $shipping = (float)($data['shipping_cost'] ?? 0);

        $data['sub_total'] = 0;
        $data['total_discount'] = 0;
        $data['tax'] = 0;

        // Initial Total = Shipping Cost (Items will be added/calculated in afterCreate)
        $data['total_amount'] = $shipping;

        return $data;
    }

    // ==========================================================
    // --- HOOK afterCreate() ---
    // Re-calculates totals based on items (if any are added during creation context)
    // ==========================================================
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        // Load items related to this PO
        $record->loadMissing('items');
        $items = $record->items;

        $subTotal = 0;
        $totalDiscountFromItems = 0;

        // 1. Calculate Item Totals
        foreach ($items as $item) {
            $price = (float)($item->price_per_item ?? 0);
            $discount = (float)($item->discount_per_item ?? 0);
            $quantity = (int)($item->quantity ?? 1);

            $subTotal += $price * $quantity;
            $totalDiscountFromItems += $discount * $quantity;
        }

        // 2. Get Shipping Cost (Already saved in DB from form)
        $shipping = (float)($record->shipping_cost ?? 0);
        $finalTotalDiscount = $totalDiscountFromItems;

        // 3. Calculate Tax
        $taxableAmount = $subTotal - $finalTotalDiscount;

        // Fetch Tax Setting
        $taxSetting = BusinessSetting::where('type', 'tax')
                        ->where('business_id', $record->business_id)
                        ->first();
        $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
        $taxAmount = ($taxableAmount * $taxPercent) / 100;

        // 4. Calculate Grand Total
        $grandTotal = $taxableAmount + $shipping + $taxAmount;

        // 5. Update Record
        $record->updateQuietly([
            'sub_total' => $subTotal,
            'total_discount' => $finalTotalDiscount,
            'tax' => $taxAmount,
            'total_amount' => $grandTotal,
        ]);
    }
}
