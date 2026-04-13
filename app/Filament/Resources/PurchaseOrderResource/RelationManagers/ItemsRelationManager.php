<?php

namespace App\Filament\Resources\PurchaseOrderResource\RelationManagers;

use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\ProductUom;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\Summarizers;
use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    // public function afterCreate(): void
    // {
    //     $this->updateParentTotals();
    // }
    // public function afterEdit(): void
    // {
    //     $this->updateParentTotals();
    // }
    // public function afterDelete(): void
    // {
    //     $this->updateParentTotals();
    // }


    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name',
                        // Kita gunakan modifyQueryUsing untuk mem-filter produk
                        modifyQueryUsing: function (Builder $query) {
                            // 1. Dapatkan parent Purchase Order record
                            $po = $this->getOwnerRecord();

                            if (!$po) {
                                // Seharusnya tidak terjadi, tapi sebagai pengaman
                                return $query->whereRaw('0 = 1');
                            }

                            if ($po->po_type === 'raw_material') {
                                $query->where('product_type', 'raw_material');
                            } elseif ($po->po_type === 'finished_goods') {
                                // (Pastikan 'finished_good' (singular) adalah nilai di DB Anda)
                                $query->where('product_type', 'finished_good');
                            } elseif ($po->po_type === 'merchandise') {
                                $query->where('product_type', 'merchandise');
                            }
                            return $query;
                        }
                    )
                    // ==========================================================
                    // --- AKHIR PERUBAHAN ---
                    // ==========================================================
                    ->searchable()->preload()->required()->reactive()
                    // Panggil helper 'updatePriceBasedOnUom' saat produk diubah
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $this->updatePriceBasedOnUom($state, $get, $set))
                    ->columnSpan(3),

                Forms\Components\TextInput::make('quantity')
                    ->label('Quantity')->numeric()->required()->default(1)->reactive()
                    // Panggil helper 'updateLineItemTotals' saat qty diubah
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->updateLineItemTotals($get, $set)),

                Forms\Components\Select::make('uom')
                    ->label('Unit')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        // Ambil UoM Tipe Purchasing
                        return ProductUom::where('product_id', $productId)
                            ->where('uom_type', 'purchasing')
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()->reactive()
                    // Panggil helper 'updatePriceBasedOnUom' saat UoM diubah
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $this->updatePriceBasedOnUom($state, $get, $set)),

                Forms\Components\TextInput::make('price_per_item')
                    ->label('Cost per Item (Harga Beli)')
                    ->prefix('Rp')->reactive()
                    ->numeric() // <-- Pastikan numeric
                    // ->mask(RawJs::make('$money($input)')) // <-- HAPUS MASK
                    // Harga Beli bisa di-overwrite, KECUALI jika konsinyasi
                    ->readOnly(fn () => $this->getOwnerRecord()->price_type === 'consignment')
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->updateLineItemTotals($get, $set)),

                Forms\Components\TextInput::make('discount_per_item')
                    ->label('Discount/Item')
                    ->prefix('Rp')->default(0)->reactive()
                    ->numeric() // <-- Pastikan numeric
                    // ->mask(RawJs::make('$money($input)')) // <-- HAPUS MASK
                    // Diskon hanya muncul jika tipe harga 'Special'
                    ->visible(fn () => $this->getOwnerRecord()->price_type === 'special')
                    ->afterStateUpdated(fn (Get $get, Set $set) => $this->updateLineItemTotals($get, $set)),

                Forms\Components\TextInput::make('total_price')
                    ->label('Subtotal per Items')
                    ->numeric()->readOnly()->prefix('Rp')
                    // ->mask(RawJs::make('$money($input)')) // <-- HAPUS MASK
                    ->dehydrated(true), // Pastikan total per baris disimpan ke DB
            ])
            ->columns(6);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->columns([
                Tables\Columns\TextColumn::make('product.name'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('uom'),
                Tables\Columns\TextColumn::make('price_per_item')->money('IDR'),
                Tables\Columns\TextColumn::make('discount_per_item')->money('IDR'),
                Tables\Columns\TextColumn::make('total_price')->money('IDR')->label('Subtotal'),
                Tables\Columns\TextColumn::make('total_price')
                    ->money('IDR')
                    ->formatStateUsing(fn (string $state): string => Number::currency($state, 'IDR'))
                    ->label('Subtotal')
                    ->summarize(Sum::make()
                        ->label('Total Subtotal')
                        ->formatStateUsing(fn (string $state): string => Number::currency($state, 'IDR'))
                    ),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data = $this->calculateTotalPrice($data);
                        return $data;
                    })
                    // ==========================================================
                    // --- GUNAKAN ->after() DI SINI ---
                    // ==========================================================
                    ->after(fn () => $this->updateParentTotals()),
            ])
            ->actions([
                    Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data = $this->calculateTotalPrice($data);
                        return $data;
                    })
                    // ==========================================================
                    // --- GUNAKAN ->after() DI SINI ---
                    // ==========================================================
                    ->after(fn () => $this->updateParentTotals()),

                Tables\Actions\DeleteAction::make()
                    // ==========================================================
                    // --- GUNAKAN ->after() DI SINI ---
                    // ==========================================================
                    ->after(fn () => $this->updateParentTotals()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        // ==========================================================
                        // --- GUNAKAN ->after() DI SINI ---
                        // ==========================================================
                        ->after(fn () => $this->updateParentTotals()),
                ]),
            ]);
    }

   public function updatePriceBasedOnUom($state, Get $get, Set $set): void
    {
        $po = $this->getOwnerRecord();
        if ($po->price_type === 'consignment') {
            $set('price_per_item', 0);
            $this->updateLineItemTotals($get, $set);
            return;
        }

        $product = Product::find($get('product_id'));
        if (!$product) return;

        $baseCost = $product->cost ?? 0;
        $uomName = $get('uom');
        if (!$uomName && $state && is_string($state)) { // Cek $state adalah string (bisa jadi product_id saat afterStateUpdated product)
             // Coba tebak UoM default purchasing
             $defaultUom = ProductUom::where('product_id', $product->id)
                             ->where('uom_type', 'purchasing')
                             ->first();
             if ($defaultUom) {
                 $uomName = $defaultUom->uom_name;
                 // Set UoM default hanya jika field uom kosong
                 if (!$get('uom')) {
                      $set('uom', $uomName);
                 }
             }
        } elseif ($state && is_string($state) && !$get('uom')) {
             // Jika state adalah UoM yang dipilih
             $uomName = $state;
        }

        $uomData = $product->uoms()->where('uom_name', $uomName)->first(); // Gunakan relasi
        $conversionRate = $uomData?->conversion_rate ?? 1;
        $finalPrice = $baseCost * $conversionRate;
        $set('price_per_item', $finalPrice);

        $this->updateLineItemTotals($get, $set);
    }

    /**
     * Helper untuk menghitung 'total_price' (subtotal per baris) di form.
     */
    public function updateLineItemTotals(Get $get, Set $set): void
    {
        $data = $this->calculateTotalPrice($get());
        $set('total_price', $data['total_price']);
    }

    /**
     * Helper KUNCI: Menghitung dan memperbarui total di PurchaseOrder (parent).
     */
    protected function updateParentTotals(): void
    {
        Log::info("--- Running updateParentTotals ---");
        $po = $this->getOwnerRecord();
        if (!$po) {
             Log::error("updateParentTotals: Cannot get Owner Record (PurchaseOrder).");
             return;
        }
        Log::info("Updating totals for PO ID: {$po->id}");

        // Ambil SEMUA item yang terhubung ke PO ini dari DB
        $po->refresh()->loadMissing('items');
        $items = $po->items;
        Log::info("Found " . $items->count() . " items for this PO.");

        $subTotal = 0;
        $totalDiscountFromItems = 0;

        // 1. Hitung Subtotal dan Diskon dari Item
        foreach ($items as $item) {
            $price = (float)($item->price_per_item ?? 0);
            $discount = (float)($item->discount_per_item ?? 0);
            $quantity = (int)($item->quantity ?? 1);
            $subTotal += $price * $quantity;
            $totalDiscountFromItems += $discount * $quantity;
        }
        Log::info("Calculated Subtotal: {$subTotal}, Total Discount from Items: {$totalDiscountFromItems}");

        // 2. Ambil Biaya Lainnya dari PO Header
        $shipping = (float)($po->shipping_cost ?? 0);
        $finalTotalDiscount = $totalDiscountFromItems;
        Log::info("Shipping Cost: {$shipping}");

        // 3. Kalkulasi Pajak
        $taxableAmount = $subTotal - $finalTotalDiscount;
        $taxSetting = BusinessSetting::where('type', 'tax')->where('business_id', $po->business_id)->first();
        $taxPercent = $taxSetting ? (float)$taxSetting->value : 0;
        $taxAmount = ($taxableAmount * $taxPercent) / 100;
        Log::info("Taxable Amount: {$taxableAmount}, Tax Percent: {$taxPercent}, Tax Amount: {$taxAmount}");

        // 4. Hitung Grand Total final
        $grandTotal = $taxableAmount + $shipping + $taxAmount;
        Log::info("Calculated Grand Total: {$grandTotal}");

        // 5. Lakukan UPDATE pada PO (parent)
        try {
            $updateData = [
                'sub_total' => $subTotal,
                'total_discount' => $finalTotalDiscount,
                'tax' => $taxAmount,
                'total_amount' => $grandTotal,
            ];
            Log::info("Attempting to update PO with data: ", $updateData);
            $po->updateQuietly($updateData);
            Log::info("--- updateParentTotals finished successfully ---");

            // ==========================================================
            // --- KIRIM EVENT UNTUK REFRESH FORM HEADER ---
            // ==========================================================
            // Nama event bisa apa saja, misal 'poTotalsUpdated'
            $this->dispatch('poTotalsUpdated');
            Log::info("Dispatched 'poTotalsUpdated' event.");
            // ==========================================================

        } catch (\Exception $e) {
             Log::error("Error updating PO totals: " . $e->getMessage());
        }
    }

    /**
     * Helper terpusat untuk kalkulasi total_price per baris (sebelum disimpan)
     */
    private function calculateTotalPrice(array $data): array
    {
        $po = $this->getOwnerRecord();

        if ($po->price_type === 'consignment') {
            $data['price_per_item'] = 0;
            $data['discount_per_item'] = 0;
            $data['total_price'] = 0;
        } else {
            // Ambil data sebagai angka murni
            $price = (float) ($data['price_per_item'] ?? 0);
            $discount = (float) ($data['discount_per_item'] ?? 0);
            $quantity = (int)($data['quantity'] ?? 1);

            // Simpan angka bersih kembali ke data
            $data['price_per_item'] = $price;
            $data['discount_per_item'] = $discount;

            // Hitung total
            $data['total_price'] = ($price - $discount) * $quantity;
        }
        return $data;
    }

}
