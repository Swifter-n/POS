<?php

namespace App\Filament\Resources\TransactionResource\Pages;

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
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class EditTransaction extends EditRecord
{
    protected static string $resource = TransactionResource::class;
    use HasPermissionChecks;

    public function form(Form $form): Form
    {
        $taxSetting = BusinessSetting::where('business_id', Auth::user()->business_id)
            ->where('type', 'tax')
            ->where('status', true)
            ->first();
        $defaultTax = $taxSetting ? $taxSetting->value : 0;

        return $form
            ->schema([
                Section::make('Order Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Select::make('product_id')
                                    ->label('Product')
                                    ->options(
                                        Product::where('business_id', Auth::user()->business_id)
                                            ->where('product_type', 'finished_good')
                                            ->pluck('name', 'id')
                                            ->toArray()
                                    )
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $livewire, $state) {
                                        if (empty($state)) return;
                                        // Panggil helper LOKAL (Get/Set)
                                        $livewire->updateLineItemPrice($get, $set);
                                    })
                                    ->columnSpan(3),

                                Select::make('uom')
                                    ->label('Unit')
                                    ->options(function (Get $get): array {
                                        $product = Product::find($get('product_id'));
                                        if (!$product) return [];
                                        return $product->uoms()
                                            ->where('uom_type', 'selling')
                                            ->pluck('uom_name', 'uom_name')
                                            ->toArray();
                                    })
                                    ->live()
                                    ->required()
                                    ->dehydrated(false) // UoM tidak disimpan di DB 'order_items'
                                    ->afterStateUpdated(function (Get $get, Set $set, $livewire) {
                                        // Panggil helper LOKAL (Get/Set)
                                        $livewire->updateLineItemFromUom($get, $set);
                                    })
                                    ->columnSpan(2),

                                TextInput::make('quantity')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(1)
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, $livewire) {
                                        // Panggil helper LOKAL (Get/Set)
                                        $livewire->updateLineItemTotal($get, $set);
                                    })
                                    ->columnSpan(1),

                                TextInput::make('price')
                                    ->label('Price / UoM')
                                    ->numeric()->readOnly()->prefix('Rp')
                                    ->columnSpan(2),

                                TextInput::make('total')
                                    ->numeric()->readOnly()->prefix('Rp')
                                    ->columnSpan(2),

                                Textarea::make('note')->label('Note')->rows(2)->columnSpanFull(),
                            ])
                            ->columns(10)
                            ->live()
                            ->afterStateUpdated(function ($livewire) { // PANGGIL GLOBAL
                                $livewire->updateTotals();
                            })
                            ->deleteAction(
                                fn (FormAction $action) => $action->after(
                                    fn ($livewire) => $livewire->updateTotals() // PANGGIL GLOBAL
                                )
                            )
                            ->addActionLabel('Add Item')
                            ->collapsible()
                            ->cloneable(),
                    ]),

                //=========================================================
                // BAGIAN 2: PEMBAYARAN & TOTAL
                //=========================================================
                Section::make('Payment')
                    ->schema([
                        Grid::make(2)->schema([
                            Hidden::make('outlet_id'),
                            Hidden::make('type_order'),

                            TextInput::make('sub_total')->numeric()->readOnly()->prefix('Rp')->default(0),
                            TextInput::make('tax')->numeric()->readOnly()->prefix('Rp')->default(0)
                                ->helperText(fn() => "Tax Rate: " . ($defaultTax) . "%"),
                            TextInput::make('promo_code_input')
                                ->label('Promo Code')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($livewire) { // PANGGIL GLOBAL
                                    $livewire->updateTotals();
                                })
                                ->placeholder('Enter promo code'),
                            TextInput::make('discount')->numeric()->readOnly()->prefix('Rp')->default(0),

                            // --- Placeholder Diskon (dari TransactionResource) ---
                            Placeholder::make('applied_discounts_display')
                                ->label('Applied Discounts')
                                ->content(function (Get $get) {
                                    // Ini akan diisi oleh $this->data['applied_discounts_display']
                                    return $get('applied_discounts_display') ?? '';
                                })
                                ->dehydrated(false)
                                ->columnSpanFull(),

                            TextInput::make('total_items')->numeric()->readOnly()->suffix('Items')->default(0),
                            TextInput::make('total_price')
                                ->label('Grand Total')
                                ->numeric()->readOnly()->prefix('Rp')->default(0)
                                ->extraAttributes(['class' => 'font-bold text-lg']),
                            Select::make('payment_method')
                                ->options(['cash' => 'Cash', 'card' => 'Debit/Credit Card', 'qris' => 'QRIS', 'transfer' => 'Bank Transfer'])
                                ->required()->default('cash')->live(),
                            FileUpload::make('proof')
                                ->image()->maxSize(2048)->directory('payment-proofs')
                                ->columnSpanFull()
                                ->visible(fn (Get $get) => in_array($get('payment_method'), ['card', 'qris', 'transfer'])),
                        ]),
                    ])
            ]);
    }


    // ==========================================================
    // --- PUSAT HELPER UNTUK FORM (DIPERBARUI) ---
    // (Sekarang identik dengan CreateTransaction.php)
    // ==========================================================

    /**
     * Dijalankan SETELAH form diisi data dari DB.
     */
    protected function afterFill(): void
    {
        // 1. Ambil record dan muat relasi promoCode
        $record = $this->getRecord();
        $record->loadMissing('promoCode');

        // 2. Set field 'promo_code_input' di state form ($this->data)
        $this->data['promo_code_input'] = $record->promoCode?->code;

        // 3. Panggil updateTotals() SEKARANG.
        $this->updateTotals();
    }

    /**
     * Helper LOKAL (dipanggil dari Repeater)
     */
    public function updateLineItemPrice(Get $get, Set $set): void
    {
        $formState = $this->data;
        $outletId = $formState['outlet_id'] ?? null;
        $productId = $get('product_id');

        if (empty($productId) || empty($outletId)) {
            $set('price', 0); $set('total', 0); return;
        }

        $outlet = Outlet::find($outletId);
        $product = Product::find($productId);
        if (!$product) {
            $set('price', 0); $set('total', 0); return;
        }

        $price = 0;
        if ($outlet?->price_list_id) {
            $priceListItem = PriceListItem::where('price_list_id', $outlet->price_list_id)
                ->where('product_id', $productId)
                ->first();
            $price = $priceListItem?->price ?? $product?->price ?? 0;
        } else {
            $price = $product?->price ?? 0;
        }

        $baseUom = $product->base_uom ?? 'pcs';
        $set('uom', $baseUom);
        $set('price', $price);
        $set('quantity', 1);
        $set('total', $price * 1);

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
        $outletId = $this->data['outlet_id'] ?? null;

        if (empty($productId) || empty($uomName) || empty($outletId)) {
            return;
        }

        $product = Product::with('uoms')->find($productId);
        $outlet = Outlet::find($outletId);
        if (!$product) return;

        $basePrice = 0;
        if ($outlet?->price_list_id) {
            $priceListItem = PriceListItem::where('price_list_id', $outlet->price_list_id)
                ->where('product_id', $productId)
                ->first();
            $basePrice = $priceListItem?->price ?? $product?->price ?? 0;
        } else {
            $basePrice = $product?->price ?? 0;
        }

        $uomData = $product->uoms->where('uom_name', $uomName)->first();
        $conversionRate = $uomData?->conversion_rate ?? 1;
        $pricePerSelectedUom = $basePrice * $conversionRate;
        $set('price', $pricePerSelectedUom);

        $this->updateLineItemTotal($get, $set);
    }

    /**
     * Helper LOKAL (dipanggil dari Repeater)
     */
    public function updateLineItemTotal(Get $get, Set $set): void
    {
         $quantity = (int)$get('quantity');
         $price = (float)$get('price');
         $set('total', $quantity * $price);

         $this->updateTotals();
    }


    /**
     * Helper GLOBAL untuk menghitung total keseluruhan order.
     */
    public function updateTotals(): void
    {
        $state = $this->data;

        $items = $state['items'] ?? [];
        $businessId = Auth::user()->business_id;
        $subTotal = collect($items)->sum('total');

        $taxSetting = BusinessSetting::where('business_id', $businessId)
            ->where('type', 'tax')->where('status', true)->first();
        $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
        $taxAmount = ($subTotal * $taxPercent) / 100;

        $promoCode = $state['promo_code_input'] ?? null;

        Log::info('===== updateTotals() [EDIT PAGE] DIPANGGIL =====');
        Log::info('Promo Code (dari state): ', ['code' => $promoCode]);
        Log::info('Items Count (dari state): ', ['count' => count($items)]);

        $discountResult = DiscountService::calculate($items, $subTotal, $businessId, $promoCode, null);
        $discountAmount = $discountResult['total_discount'];
        $appliedRules = $discountResult['applied_rules'] ?? []; // <-- Ambil daftarnya

        Log::info('Discount Calculated: ', ['amount' => $discountAmount]);

        $grandTotal = ($subTotal + $taxAmount) - $discountAmount;

        // Format daftar aturan menjadi string HTML
        $rulesString = '';
        if (!empty($appliedRules)) {
            $rulesString = '<ul class="list-disc list-inside text-xs text-success-500 -mt-2">';
            foreach ($appliedRules as $ruleName) {
                $rulesString .= '<li>' . e($ruleName) . '</li>';
            }
            $rulesString .= '</ul>';
        }

        // TULIS KE $this->data
        $this->data['items'] = $items;
        $this->data['sub_total'] = $subTotal;
        $this->data['tax'] = $taxAmount;
        $this->data['discount'] = $discountAmount;
        $this->data['total_price'] = $grandTotal;
        $this->data['total_items'] = collect($items)->sum('quantity');

        $this->data['applied_discounts_display'] = $rulesString;
    }

    // ==========================================================

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Ambil nilai total dari $this->data (state global)
        $data['sub_total'] = $this->data['sub_total'] ?? 0;
        $data['tax'] = $this->data['tax'] ?? 0;
        $data['discount'] = $this->data['discount'] ?? 0;
        $data['total_price'] = $this->data['total_price'] ?? 0;

        // (Logika konversi UoM untuk 'items' tidak diperlukan
        // karena 'relationship()' akan menanganinya secara terpisah)

        // Hitung ulang total_items berdasarkan item yang disimpan
        $recordItems = $this->getRecord()->items()->get();
        $data['total_items'] = $recordItems->sum('quantity');

        // Cari ID promo
        $promoCodeString = $data['promo_code_input'] ?? null;
        if ($promoCodeString) {
            $promo = Promo::where('code', $promoCodeString)
                ->where('business_id', Auth::user()->business_id)
                ->first();
            $data['promo_code'] = $promo?->id;
        } else {
            $data['promo_code'] = null;
        }
        unset($data['promo_code_input']);

        // Hapus field display dari data yang akan disimpan
        unset($data['applied_discounts_display']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
