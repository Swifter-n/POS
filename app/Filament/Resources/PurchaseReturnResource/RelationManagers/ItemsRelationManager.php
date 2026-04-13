<?php

namespace App\Filament\Resources\PurchaseReturnResource\RelationManagers;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected function canCreate(): bool { return $this->getOwnerRecord()->status === 'draft'; }
    protected function canEdit(Model $record): bool { return $this->getOwnerRecord()->status === 'draft'; }
    protected function canDelete(Model $record): bool { return $this->getOwnerRecord()->status === 'draft'; }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // 1. Pilih Produk
                Forms\Components\Select::make('product_id')
                    ->label('Product from Return/Damage Zones')
                    ->options(function (RelationManager $livewire): array {
                        $warehouseId = $livewire->getOwnerRecord()->warehouse_id;
                        if (!$warehouseId) return [];
                        $targetZoneIds = Zone::whereIn('code', ['DMG', 'QI', 'RET'])->pluck('id');
                        $locationIds = Location::where('locatable_id', $warehouseId)
                                     ->where('locatable_type', Warehouse::class)
                                     ->whereIn('zone_id', $targetZoneIds)
                                     ->where('status', true)
                                     ->pluck('id');
                        return Inventory::whereIn('location_id', $locationIds)
                                ->where('avail_stock', '>', 0)
                                ->with('product:id,name')
                                ->get()
                                ->pluck('product.name', 'product.id')
                                ->unique()
                                ->toArray(); // Tambahkan toArray()
                    })
                    ->searchable()->preload()->required()->reactive()
                    ->columnSpan(2)
                    ->afterStateUpdated(fn(Set $set) => $set('inventory_id', null)),

                // 2. Pilih Batch (Inventory ID)
                Forms\Components\Select::make('inventory_id')
                    ->label('Select Batch/SLED (From DMG/QI/RET Zones)')
                    ->options(function (Get $get, RelationManager $livewire): array {
                        $warehouseId = $livewire->getOwnerRecord()->warehouse_id;
                        $productId = $get('product_id');
                        if (!$warehouseId || !$productId) return [];
                        $targetZoneIds = Zone::whereIn('code', ['DMG', 'QI', 'RET'])->pluck('id');
                        $locationIds = Location::where('locatable_id', $warehouseId)
                                     ->where('locatable_type', Warehouse::class)
                                     ->whereIn('zone_id', $targetZoneIds)
                                     ->where('status', true)
                                     ->pluck('id');
                        return Inventory::whereIn('location_id', $locationIds)
                                    ->where('product_id', $productId)
                                    ->where('avail_stock', '>', 0)
                                    ->with('product:id,base_uom')
                                    ->get()
                                    ->mapWithKeys(fn($inv) => [
                                        $inv->id => "Batch: {$inv->batch} | SLED: {$inv->sled?->format('d/m/Y')} | Stock: {$inv->avail_stock} {$inv->product?->base_uom}"
                                    ])
                                    ->toArray(); // Tambahkan toArray()
                    })
                    ->searchable()->preload()->required()->reactive(),

                // 3. Input Kuantitas & UoM
                Forms\Components\TextInput::make('quantity')
                    ->numeric()->required()->minValue(1)
                    ->reactive(),
                Forms\Components\Select::make('uom')
                    ->label('Unit')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        return ProductUom::where('product_id', $productId)
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()->reactive()->default('PCS'),

                Forms\Components\TextInput::make('reason')->required()->columnSpan(2),
                Hidden::make('batch'),
                Hidden::make('sled'),
            ])
            ->columns(4);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->searchable(),
                Tables\Columns\TextColumn::make('batch'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('uom'),
                Tables\Columns\TextColumn::make('reason'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->visible(fn(RelationManager $livewire) => $livewire->getOwnerRecord()->status === 'draft')
                    ->mutateFormDataUsing(function (array $data): array {
                        $inventory = Inventory::find($data['inventory_id']);
                        if (!$inventory) throw new \Exception('Inventory batch not found.');
                        $product = $inventory->product;
                        $product->loadMissing('uoms');

                        $uomData = $product->uoms->where('uom_name', $data['uom'])->first();
                        if (!$uomData) throw new \Exception("UoM {$data['uom']} not found.");
                        $conversionRate = $uomData->conversion_rate ?? 1;
                        $quantityInBaseUom = (float)$data['quantity'] * $conversionRate;

                        if (round($quantityInBaseUom, 5) > round($inventory->avail_stock, 5)) {
                             throw ValidationException::withMessages([
                                 'quantity' => "Return quantity ({$quantityInBaseUom} base) cannot exceed available stock ({$inventory->avail_stock} base)."
                             ]);
                        }

                        $data['product_id'] = $inventory->product_id;
                        $data['batch'] = $inventory->batch;
                        $data['sled'] = $inventory->sled;

                        // ==========================================================
                        // --- TAMBAHKAN BARIS INI (PERBAIKAN) ---
                        // ==========================================================
                        $data['quantity_base_uom'] = $quantityInBaseUom;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn(Model $record) => $this->getOwnerRecord()->status === 'draft')
                    ->mutateFormDataUsing(function (array $data, Model $record): array {
                        // (Logika validasi stok saat edit, mirip Create)
                        $inventory = Inventory::find($data['inventory_id']);
                        if (!$inventory) throw new \Exception('Inventory batch not found.');
                        $product = $inventory->product;
                        $product->loadMissing('uoms');
                        $uomData = $product->uoms->where('uom_name', $data['uom'])->first();
                        $conversionRate = $uomData?->conversion_rate ?? 1;
                        $quantityInBaseUom = (float)$data['quantity'] * $conversionRate;

                        $availableStock = $inventory->avail_stock;
                        $originalQtyInBase = 0;
                        if ($record->inventory_id == $data['inventory_id']) {
                             $originalUom = $product->uoms->where('uom_name', $record->uom)->first();
                             $originalConversionRate = $originalUom?->conversion_rate ?? 1;
                             $originalQtyInBase = (float)$record->quantity * $originalConversionRate;
                        }
                        $totalAvailableForThisItem = $availableStock + $originalQtyInBase;

                        if (round($quantityInBaseUom, 5) > round($totalAvailableForThisItem, 5)) {
                             throw ValidationException::withMessages([
                                 'quantity' => "Return quantity ({$quantityInBaseUom} base) cannot exceed total available stock ({$totalAvailableForThisItem} base)."
                             ]);
                        }

                        $data['product_id'] = $inventory->product_id;
                        $data['batch'] = $inventory->batch;
                        $data['sled'] = $inventory->sled;

                        // ==========================================================
                        // --- TAMBAHKAN BARIS INI JUGA (PERBAIKAN) ---
                        // ==========================================================
                        $data['quantity_base_uom'] = $quantityInBaseUom;

                        return $data;
                    }),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn(Model $record) => $this->getOwnerRecord()->status === 'draft'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                         ->visible(fn(RelationManager $livewire) => $livewire->getOwnerRecord()->status === 'draft'),
                ]),
            ]);
    }
}
