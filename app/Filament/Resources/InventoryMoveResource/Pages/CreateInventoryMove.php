<?php

namespace App\Filament\Resources\InventoryMoveResource\Pages;

use App\Filament\Resources\InventoryMoveResource;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Plant;
use App\Models\Warehouse;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateInventoryMove extends CreateRecord
{
    protected static string $resource = InventoryMoveResource::class;

    use CreateRecord\Concerns\HasWizard;

    protected function getSteps(): array
    {
        return [
            Step::make('Source')
                ->description('Select the item you want to move')
                ->schema([
                    Select::make('plant_id')
                        ->label('Plant')
                        ->options(fn() => Plant::where('business_id', Auth::user()->business_id)->where('status', true)->pluck('name', 'id'))
                        ->searchable()->preload()->live()->required(),
                    Select::make('warehouse_id')
                        ->label('Warehouse')
                        ->options(fn(Get $get) => Warehouse::where('plant_id', $get('plant_id'))->where('status', true)->pluck('name', 'id'))
                        ->searchable()->preload()->live()->required(),
                    Select::make('source_location_id')
                        ->label('Source Location')
                        ->options(fn(Get $get) => Location::where('locatable_type', Warehouse::class)->where('locatable_id', $get('warehouse_id'))->where('status', true)->pluck('name', 'id'))
                        ->searchable()->preload()->live()->required(),

                    // Filter Inventory berdasarkan Source Location
                    Select::make('inventory_id')
                        ->label('Item (Product - Batch - SLED - Stock)')
                        ->options(function (Get $get): array {
                            $sourceLocationId = $get('source_location_id');
                            if (!$sourceLocationId) return [];
                            return Inventory::where('location_id', $sourceLocationId)
                                        ->where('avail_stock', '>', 0)
                                        ->with('product:id,name,base_uom')
                                        ->get()
                                        ->mapWithKeys(fn($inv) => [
                                            $inv->id => ($inv->product?->name ?? 'N/A') . " (Batch: {$inv->batch}) | Stock: {$inv->avail_stock} {$inv->product?->base_uom}"
                                        ])
                                        ->toArray(); // <-- [PERBAIKAN] Konversi Collection ke array
                        })
                        ->searchable()->preload()->live()->required(),

                    // Tambahkan UoM Convertion (seperti di GoodsReturn)
                    Select::make('input_uom')
                        ->label('Move Unit (UoM)')
                        ->options(function (Get $get): array {
                            $inventory = Inventory::find($get('inventory_id'));
                            if (!$inventory) return [];
                            $product = $inventory->product;
                            if (!$product) return [];
                            return $product->uoms()->pluck('uom_name', 'uom_name')->toArray() ?? [];
                        })
                        ->required()->reactive()->default(function(Get $get): string {
                             $inventory = Inventory::find($get('inventory_id'));
                             return $inventory?->product?->base_uom ?? 'PCS';
                        }),

                    TextInput::make('input_quantity')
                        ->label('Move Quantity')
                        ->numeric()->required()->minValue(0.0001)
                        ->rule(function (Get $get) {
                            // Validasi stok saat input
                            return function (string $attribute, $value, \Closure $fail) use ($get) {
                                $inventory = Inventory::find($get('inventory_id'));
                                if (!$inventory) return;

                                $inventory->loadMissing('product.uoms');

                                $uomData = $inventory->product->uoms()->where('uom_name', $get('input_uom'))->first();
                                $conversionRate = $uomData?->conversion_rate ?? 1;
                                $quantityInBaseUom = (float)$value * $conversionRate;

                                if (round($quantityInBaseUom, 5) > round($inventory->avail_stock, 5)) {
                                    $fail("Move quantity ({$quantityInBaseUom} base) cannot exceed available stock ({$inventory->avail_stock} base).");
                                }
                            };
                        }),

                ])->columns(2),

            Step::make('Destination')
                ->description('Select where the item is going')
                ->schema([
                     Select::make('destination_location_id')
                        ->label('Destination Location')
                        ->options(function (Get $get) {
                            $warehouseId = $get('warehouse_id'); // Ambil dari Step 1
                            $sourceLocationId = $get('source_location_id');
                            if (!$warehouseId) return [];

                            return Location::where('locatable_type', Warehouse::class)
                                        ->where('locatable_id', $warehouseId)
                                        ->where('status', true)
                                        // Jangan pindah ke lokasi yang sama
                                        ->where('id', '!=', $sourceLocationId)
                                        ->pluck('name', 'id');
                        })
                        ->searchable()->preload()->required(),
                    Textarea::make('reason')
                        ->label('Reason for Movement')
                        ->required()
                        ->helperText('Contoh: Koreksi Put-Away, Replenishment, Pindah Bin.')
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * Menyiapkan data sebelum dikirim ke Model (untuk 'creating' event)
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $inventory = Inventory::find($data['inventory_id']);
        $inventory->loadMissing('product.uoms');

        // Konversi Qty ke Base UoM
        $uomData = $inventory->product->uoms()->where('uom_name', $data['input_uom'])->first();
        $conversionRate = $uomData?->conversion_rate ?? 1;
        $quantityInBaseUom = (float)$data['input_quantity'] * $conversionRate;

        // Data yang dikirim ke Model (sesuai $guarded di Model)
        $data['plant_id'] = $data['plant_id'];
        $data['warehouse_id'] = $data['warehouse_id'];
        $data['source_location_id'] = $data['source_location_id'];
        $data['destination_location_id'] = $data['destination_location_id'];
        $data['inventory_id'] = $data['inventory_id'];
        $data['product_id'] = $inventory->product_id; // Ambil dari inventory
        $data['quantity_base'] = $quantityInBaseUom; // Simpan Qty Base
        $data['reason'] = $data['reason'];

        // ==========================================================
        // --- [PERBAIKAN] Simpan juga input asli user ---
        // ==========================================================
        $data['input_quantity'] = (float)$data['input_quantity'];
        $data['input_uom'] = $data['input_uom'];
        // ==========================================================

        return $data;
    }

    // Redirect ke halaman Index (List) setelah Create
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
