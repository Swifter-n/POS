<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Events\ConsignmentStockConsumed;
use App\Filament\Resources\TransactionResource;
use App\Models\BusinessSetting;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Promo;
use App\Models\Zone;
use App\Services\DiscountService;
use Filament\Actions;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

  public $currentStep = 1;

  protected function getFormActions(): array
    {
        // 1. Dapatkan *index* step wizard yang sedang aktif
        //    (Properti ini diisi oleh hook 'afterStepPersisted' di Resource)
        $activeStepIndex = $this->currentStep;

        // 2. Tentukan *index* dari step terakhir Anda
        //    (Step 1: Details, Step 2: Items, Step 3: Payment)
        $finalStepIndex = 3;

        // 3. Jika kita TIDAK berada di step terakhir...
        if ($activeStepIndex !== $finalStepIndex) {
            // ...kembalikan array kosong.
            return [];
        }

        // 4. Jika kita BERADA di step terakhir, tampilkan tombol default.
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction(),
            $this->getCancelFormAction(),
        ];
    }


   public function updateLineItemPrice(Get $get, Set $set): void
    {
        // Ambil state form lengkap (independen dari $get)
        // Kita gunakan $this->data karena $this->form->getState() step-scoped
        $formState = $this->data;
        $outletId = $formState['outlet_id'] ?? null;
        $productId = $get('product_id'); // Ambil dari field saat ini

        if (empty($productId) || empty($outletId)) {
            $set('price', 0);
            $set('total', 0);
            return;
        }

        $outlet = Outlet::find($outletId);
        $product = Product::find($productId);
        if (!$product) {
            $set('price', 0); $set('total', 0); return;
        }

        $price = 0;

        // Logika cari harga (PriceList Outlet -> Harga Dasar Produk)
        if ($outlet?->price_list_id) {
            $priceListItem = PriceListItem::where('price_list_id', $outlet->price_list_id)
                ->where('product_id', $productId)
                ->first();
            $price = $priceListItem?->price ?? $product?->price ?? 0;
        } else {
            $price = $product?->price ?? 0;
        }

        // Set UoM ke Base UoM saat produk diganti
        $baseUom = $product->base_uom ?? 'pcs';
        $set('uom', $baseUom);
        $set('price', $price); // Harga per Base UoM
        $set('quantity', 1); // Reset kuantitas ke 1
        $set('total', $price * 1); // Total = harga x 1

        // Panggil updateTotals (global) setelah harga item diubah
        $this->updateTotals();
    }

    /**
     * Helper LOKAL (dipanggil dari Repeater)
     */
    public function updateLineItemFromUom(Get $get, Set $set): void
    {
        $productId = $get('product_id');
        $uomName = $get('uom');
        $outletId = $this->data['outlet_id'] ?? null; // Baca dari state global

        if (empty($productId) || empty($uomName) || empty($outletId)) {
            return; // Belum siap
        }

        $product = Product::with('uoms')->find($productId);
        $outlet = Outlet::find($outletId);
        if (!$product) return;

        // 1. Dapatkan harga dasar (PER BASE UOM)
        $basePrice = 0;
        if ($outlet?->price_list_id) {
            $priceListItem = PriceListItem::where('price_list_id', $outlet->price_list_id)
                ->where('product_id', $productId)
                ->first();
            $basePrice = $priceListItem?->price ?? $product?->price ?? 0;
        } else {
            $basePrice = $product?->price ?? 0;
        }

        // 2. Dapatkan conversion rate dari UoM yang dipilih
        $uomData = $product->uoms->where('uom_name', $uomName)->first();
        $conversionRate = $uomData?->conversion_rate ?? 1;

        // 3. Set harga BARU (Harga per UoM yang dipilih)
        $pricePerSelectedUom = $basePrice * $conversionRate;
        $set('price', $pricePerSelectedUom);

        // 4. Panggil updateLineItemTotal untuk menghitung ulang total
        $this->updateLineItemTotal($get, $set);
    }


    /**
     * Helper untuk menghitung total per baris (hanya jika qty berubah).
     */
    public function updateLineItemTotal(Get $get, Set $set): void
{
    // Pastikan konversi ke numeric dengan benar
    $quantity = (float)($get('quantity') ?? 0);
    $price = (float)($get('price') ?? 0);

    $total = $quantity * $price;
    $set('total', $total);

    // Panggil updateTotals (global) setelah total baris diubah
    $this->updateTotals();
}


    /**
     * Helper untuk menghitung total keseluruhan order.
     * (PERBAIKAN: Dibuat agar independen dari $get dan $set)
     */
    public function updateTotals(): void
{
    // Ambil state form lengkap dari properti $data
    $state = $this->data;

    $items = $state['items'] ?? [];
    $businessId = Auth::user()->business_id;

    // Pastikan semua nilai di-cast ke float
    $subTotal = collect($items)->sum(function ($item) {
        return (float)($item['total'] ?? 0);
    });

    $taxSetting = BusinessSetting::where('business_id', $businessId)
        ->where('type', 'tax')->where('status', true)->first();
    $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
    $taxAmount = ($subTotal * $taxPercent) / 100;

    // Ambil promo code dari state form lengkap
    $promoCode = $state['promo_code_input'] ?? null;
    $discountResult = DiscountService::calculate($items, $subTotal, $businessId, $promoCode, null);
    $discountAmount = (float)($discountResult['total_discount'] ?? 0);
    $appliedRules = $discountResult['applied_rules'] ?? [];

    // Format daftar aturan menjadi string HTML
    $rulesString = '';
    if (!empty($appliedRules)) {
        $rulesString = '<ul class="list-disc list-inside text-xs text-success-500 -mt-2">';
        foreach ($appliedRules as $ruleName) {
            $rulesString .= '<li>' . e($ruleName) . '</li>';
        }
        $rulesString .= '</ul>';
    }

    $grandTotal = ($subTotal + $taxAmount) - $discountAmount;

    // TULIS KE $this->data dengan type yang benar
    $this->data['items'] = $items;
    $this->data['sub_total'] = round($subTotal, 2);
    $this->data['tax'] = round($taxAmount, 2);
    $this->data['discount'] = round($discountAmount, 2);
    $this->data['total_price'] = round($grandTotal, 2);
    $this->data['total_items'] = collect($items)->sum(function ($item) {
        return (int)($item['quantity'] ?? 0);
    });
    $this->data['applied_discounts_display'] = $rulesString;
}

protected function mutateFormDataBeforeCreate(array $data): array
{
    $user = Auth::user();
    $data['business_id'] = $user->business_id;
    $data['user_id'] = $user->id;

    // Logika Status Dinamis
    if (isset($data['type_order']) && $data['type_order'] === 'Store') {
        $data['status'] = 'pending';
    } else {
        $data['status'] = 'paid';
    }

    // Ambil nilai total dengan type casting yang benar
    $data['sub_total'] = (float)($this->data['sub_total'] ?? 0);
    $data['tax'] = (float)($this->data['tax'] ?? 0);
    $data['discount'] = (float)($this->data['discount'] ?? 0);
    $data['total_price'] = (float)($this->data['total_price'] ?? 0);
    $data['total_items'] = (int)($this->data['total_items'] ?? 0);

    // Perbaikan promo code
    $promoCodeString = $data['promo_code_input'] ?? null;
    if ($promoCodeString) {
        $promo = Promo::where('code', $promoCodeString)
            ->where('business_id', $user->business_id)
            ->first();
        $data['promo_code'] = $promo?->id;
    } else {
        $data['promo_code'] = null;
    }
    unset($data['promo_code_input']);
    unset($data['applied_discounts_display']);

    Log::info("Creating POS Order: ", $data);

    return $data;
}



}


