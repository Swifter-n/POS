<?php

namespace App\Filament\Resources\SalesOrderResource\RelationManagers;

use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\ProductUom;
use App\Services\DiscountService;
use App\Services\PricingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

   public function afterCreate(): void
    {
        $this->updateParentTotals();
    }
    public function afterEdit(): void
    {
        $this->updateParentTotals();
    }
    public function afterDelete(): void
    {
        $this->updateParentTotals();
    }

    // ==========================================================
    // FORM UNTUK CREATE/EDIT ITEM
    // ==========================================================
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')->label('Product')
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name',
                        // Filter hanya produk yang 'finished_good' atau 'merchandise'
                        modifyQueryUsing: fn(Builder $query) =>
                            $query->whereIn('product_type', ['finished_good', 'merchandise'])
                                  ->where('business_id', Auth::user()->business_id) // Filter by business
                    )
                    ->searchable()->preload()->required()->reactive()
                    ->afterStateUpdated(function(Get $get, Set $set) {
                        // Reset UoM & Panggil kalkulasi
                        $set('uom', null);
                        $this->updateLineItemPrice($get, $set);
                    })
                    ->columnSpan(4), // Perlebar

                Forms\Components\TextInput::make('quantity')->numeric()->required()->reactive()->default(1)
                    ->minValue(1)
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->updateLineItemPrice($get, $set))
                    ->columnSpan(2),

                Forms\Components\Select::make('uom')->label('Unit')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        // Filter UoM, hanya tampilkan yang tipenya 'selling'
                        return ProductUom::where('product_id', $productId)
                            ->where('uom_type', 'selling')
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()->reactive()
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->updateLineItemPrice($get, $set))
                    ->columnSpan(2),

                // Field read-only yang dihitung otomatis
                Forms\Components\TextInput::make('price_per_item')
                    ->label('Price (Base)') // <-- Label diubah
                    ->numeric()->readOnly()->prefix('Rp')
                    ->columnSpan(2),
                Forms\Components\TextInput::make('discount_per_item')
                    ->label('Discount/Item')
                    ->numeric()->readOnly()->prefix('Rp')->default(0)
                    ->columnSpan(2),
                Forms\Components\TextInput::make('total_price')
                    ->label('Total Price (Nett)') // <-- Label diubah
                    ->numeric()->readOnly()->prefix('Rp')
                    ->dehydrated(true) // Pastikan ini disimpan ke DB
                    ->columnSpan(2),
            ])
            ->columns(6);
    }

    // ==========================================================
    // TABEL UNTUK MENAMPILKAN ITEM
    // ==========================================================
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->searchable(),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('uom'),
                Tables\Columns\TextColumn::make('price_per_item')->money('IDR')->label('Price (Base)'), // <-- Label diubah
                Tables\Columns\TextColumn::make('discount_per_item')->money('IDR'),
                Tables\Columns\TextColumn::make('total_price')
                    ->money('IDR')->label('Subtotal (Nett)') // <-- Label diubah
                    ->summarize(Sum::make()->money('IDR')),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data) => $this->calculateTotalPrice($data))
                    ->after(fn () => $this->updateParentTotals()), // Panggil update setelah create
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data) => $this->calculateTotalPrice($data))
                    ->after(fn () => $this->updateParentTotals()), // Panggil update setelah edit
                Tables\Actions\DeleteAction::make()
                    ->after(fn () => $this->updateParentTotals()), // Panggil update setelah delete
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                         ->after(fn () => $this->updateParentTotals()), // Panggil update setelah bulk delete
                ]),
            ]);
    }

    // ==========================================================
    // HELPER FUNCTIONS (Logika Kalkulasi Diperbarui)
    // ==========================================================

    /**
     * Helper untuk menghitung harga, diskon, dan total per baris item (di Form).
     */
    public function updateLineItemPrice(Get $get, Set $set): void
    {
        // Panggil helper kalkulasi (yang mengembalikan array data)
        $data = $this->calculateTotalPrice($get());

        // Set nilai ke form
        $set('price_per_item', $data['price_per_item']);
        $set('discount_per_item', $data['discount_per_item']);
        $set('total_price', $data['total_price']);
    }

    /**
     * Helper terpusat untuk kalkulasi (digunakan oleh form() dan mutateFormDataUsing()).
     */
    private function calculateTotalPrice(array $data): array
    {
        $so = $this->getOwnerRecord();
        $so->loadMissing('customer'); // Pastikan customer dimuat
        $customer = $so->customer;

        $product = Product::find($data['product_id']);
        $selectedUomName = $data['uom'] ?? null; // Tambah ?? null
        $quantity = (int)($data['quantity'] ?? 1);

        if (!$customer || !$product || !$selectedUomName) {
            $data['total_price'] = 0;
            $data['price_per_item'] = 0;
            $data['discount_per_item'] = 0;
            return $data;
        }

        // ==========================================================
        // --- LOGIKA KALKULASI DIPERBARUI (Hapus DiscountService) ---
        // ==========================================================

        // 1. Inisialisasi PricingService
        $pricingService = new PricingService();

        // 2. Dapatkan SEMUA data harga (base, diskon, final) dari PricingService

        // ==========================================================
        // --- PERBAIKAN (CELAH 3 - BAGIAN B) ---
        // Kirim $selectedUomName sebagai argumen ke-4
        // ==========================================================
        $pricingResult = $pricingService->calculateItemPricing(
            $customer,
            $product,
            $quantity,
            $selectedUomName // <-- Argumen ke-4 yang hilang
        );
        // ==========================================================

        $basePricePerPcs = (float)($pricingResult['base_price'] ?? 0);
        $discountPerPcs = (float)($pricingResult['discount_amount'] ?? 0);
        $finalPricePerPcs = (float)($pricingResult['final_price'] ?? 0);

        // 3. Dapatkan conversion rate dari UoM yang dipilih
        // (Kita perlu memuat relasi uoms, mari muat jika belum ada)
        $product->loadMissing('uoms');
        $uom = $product->uoms->where('uom_name', $selectedUomName)->first();
        $conversionRate = $uom ? $uom->conversion_rate : 1;

        // 4. Hitung harga dan diskon untuk UoM yang dipilih
        $basePricePerSelectedUom = $basePricePerPcs * $conversionRate;
        $discountPerSelectedUom = $discountPerPcs * $conversionRate;
        $finalPricePerSelectedUom = $finalPricePerPcs * $conversionRate; // (Harga final x rate)

        // 5. Set semua nilai ke array $data
        $data['price_per_item'] = $basePricePerSelectedUom; // Harga Dasar (Bruto)
        $data['discount_per_item'] = max(0, $discountPerSelectedUom); // Diskon
        $data['total_price'] = $finalPricePerSelectedUom * $quantity; // Harga Nett * Qty

        // ==========================================================

        return $data;
    }

    /**
     * Helper KUNCI: Menghitung dan memperbarui total di SalesOrder (parent).
     */
    protected function updateParentTotals(): void
    {
        Log::info("--- Running updateParentTotals (Sales Order) ---");
        $so = $this->getOwnerRecord();
        if (!$so) {
             Log::error("updateParentTotals (SO): Cannot get Owner Record (SalesOrder).");
             return;
        }

        // Ambil SEMUA item yang terhubung ke SO ini dari DB
        $so->refresh()->loadMissing('items.product.uoms'); // Ganti ke uoms
        $items = $so->items;
        Log::info("Updating totals for SO ID: {$so->id}. Found {$items->count()} items.");

        $subTotal = 0;
        $totalDiscount = 0;

        // 1. Hitung Subtotal dan Diskon dari Item
        // (Logika ini sudah benar, menghitung dari field yang disimpan)
        foreach ($items as $item) {
            $product = $item->product;
            if (!$product) continue;

            // $item->price_per_item sekarang adalah HARGA DASAR per UoM
            $basePricePerItem = (float)($item->price_per_item ?? 0);
            $discountPerItem = (float)($item->discount_per_item ?? 0);
            $quantity = (int)($item->quantity ?? 0);

            // Subtotal dihitung dari HARGA DASAR (Bruto)
            $subTotal += $basePricePerItem * $quantity;

            // Total diskon dihitung dari diskon per item
            $totalDiscount += $discountPerItem * $quantity;
        }
        Log::info("Calculated Subtotal: {$subTotal}, Total Discount: {$totalDiscount}");

        // 2. Ambil Biaya Lainnya (Asumsi shipping cost 0 di SO)
        $shipping = (float)($so->shipping_cost ?? 0);

        // 3. Kalkulasi Pajak
        $taxableAmount = $subTotal - $totalDiscount;
        $taxSetting = BusinessSetting::where('type', 'tax')->where('business_id', $so->business_id)->first();
        $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
        $taxAmount = ($taxableAmount * $taxPercent) / 100;
        Log::info("Taxable Amount: {$taxableAmount}, Tax Amount: {$taxAmount}");

        // 4. Hitung Grand Total final
        $grandTotal = $taxableAmount + $shipping + $taxAmount;
        Log::info("Calculated Grand Total: {$grandTotal}");

        // 5. Lakukan UPDATE pada SO (parent)
        try {
            $so->updateQuietly([
                'sub_total' => $subTotal,
                'total_discount' => $totalDiscount,
                'tax' => $taxAmount,
                'grand_total' => $grandTotal,
            ]);
            Log::info("--- updateParentTotals (SO) finished successfully ---");

            // Kirim event untuk me-refresh form header di Halaman Edit
            $this->dispatch('soTotalsUpdated');
            Log::info("Dispatched 'soTotalsUpdated' event.");

        } catch (\Exception $e) {
             Log::error("Error updating SO totals: " . $e->getMessage());
        }
    }
}
