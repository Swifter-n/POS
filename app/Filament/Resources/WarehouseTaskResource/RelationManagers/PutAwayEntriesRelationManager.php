<?php

namespace App\Filament\Resources\WarehouseTaskResource\RelationManagers;

use App\Models\Location;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class PutAwayEntriesRelationManager extends RelationManager
{
    use HasPermissionChecks;
    protected static string $relationship = 'putAwayEntries';

    // Properti $displayUom dan method mount()
    public ?string $displayUom = null;
    public function mount(): void
    {
        $this->displayUom = 'base';
    }

    // Method canModify()
    protected function canModify(): bool
    {
        $record = $this->getOwnerRecord(); // Ini adalah StockTransfer (PA-...)
        $user = Auth::user();
        if (!$user) return false;
        // Cek status, user assignment, dan permission
        return $record->status === 'in_progress' // <-- DIPERBARUI
               && $record->assigned_user_id == $user->id
               && $this->check($user, 'execute putaway tasks');
    }

    /**
     * Form() utama tidak digunakan
     */
    public function form(Form $form): Form
    {
        return $form->schema([]);
    }


    public function table(Table $table): Table
    {
        $canModify = $this->canModify();

        return $table
            ->recordTitleAttribute('product.name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.uoms', 'product.baseUomModel', 'destinationLocation', 'user']))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product'),

                // Logika kolom 'quantity_moved' dengan UoM
                TextColumn::make('quantity_moved')
                    ->label('Qty Moved')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(function ($state, Model $record): string {
                         // Logika lengkap mixed UoM
                        $qtyInBase = (float) $state;
                        $baseUomName = $record->product?->baseUomModel?->uom_name ?? $record->product?->base_uom ?? 'PCS';

                        if ($this->displayUom === 'base' || $this->displayUom === $baseUomName || !$record->product) {
                            return "{$qtyInBase} {$baseUomName}";
                        }

                        $record->loadMissing('product.uoms');
                        $targetUom = $record->product?->uoms
                                        ->where('uom_name', $this->displayUom)
                                        ->first();

                        if ($targetUom && ($conversionRate = (int)$targetUom->conversion_rate) > 1) {
                            $wholeUnits = floor($qtyInBase / $conversionRate);
                            $remainder = $qtyInBase % $conversionRate;

                            if ($remainder == 0) return "{$wholeUnits} {$this->displayUom}";
                            elseif ($wholeUnits == 0) return "{$remainder} {$baseUomName}";
                            else return "{$wholeUnits} {$this->displayUom} + {$remainder} {$baseUomName}";
                        }
                        return "{$qtyInBase} {$baseUomName}";
                    }),

                TextColumn::make('destinationLocation.name')
                    ->label('Destination')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.name') // Tampilkan siapa yg log
                    ->label('Logged By')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // Action 'changeDisplayUom'
                 Action::make('changeDisplayUom')
                    ->label('Display Qty In')
                    ->icon('heroicon-o-cube')->color('gray')
                    ->form(function ($livewire) {
                        return [
                            Select::make('displayUomSelect')
                                ->label('Select Unit of Measure')
                                ->live()
                                ->options(function () use ($livewire): array {
                                    $stockTransfer = $livewire->getOwnerRecord()->load('items.product.uoms');
                                    $availableUoms = $stockTransfer->items
                                        ->pluck('product.uoms')->flatten()->whereNotNull()
                                        ->whereNotNull('uom_name') // <-- Perbaikan array_flip
                                        ->pluck('uom_name')->unique()->sort()->values()->all();

                                    $options = ['base' => 'Base UoM'];

                                    foreach ($availableUoms as $uom) {
                                        $baseUomName = $stockTransfer->items->first()?->product?->base_uom ?? 'PCS';
                                        if ($uom !== $baseUomName) $options[$uom] = $uom;
                                    }
                                    return $options;
                                })
                                ->default($livewire->displayUom)
                                ->afterStateUpdated(fn ($state) => $livewire->displayUom = $state),
                        ];
                    })
                    ->action(null),

                // CreateAction 'log_entry'
                Tables\Actions\CreateAction::make('log_entry')
                    ->label('Log Put-Away Entry')
                    ->icon('heroicon-o-plus-circle')
                    ->visible($canModify)
                    ->form([
                        Select::make('stock_transfer_item_id')
                            ->label('Select Item to Put-Away')
                            ->options(function () {
                                $items = $this->getOwnerRecord()->items()
                                              ->whereHas('product') // <-- Perbaikan array_flip
                                              ->with(['product.uoms', 'putAwayEntries'])->get();

                                return $items->mapWithKeys(function ($item) {
                                    $remainingString = $this->getRemainingQuantityToPutAway($item, true); // true = string
                                    // Fallback jika nama produk null
                                    $productName = $item->product?->name ?? 'Unknown Product';
                                    return [$item->id => "{$productName} (Remaining: {$remainingString})"];
                                })
                                ->filter(fn ($value, $key) => !Str::contains($value, '(Remaining: 0 '));
                            })
                            ->required()->live(),

                        Placeholder::make('remaining_qty_details')
                            ->label('Details')
                            ->content(function (Get $get) {
                                $itemId = $get('stock_transfer_item_id');
                                if (!$itemId) return 'Please select an item.';
                                $item = StockTransferItem::find($itemId);
                                return $this->getRemainingQuantityToPutAway($item, false); // false = detail
                            }),

                        Select::make('input_uom')
                            ->label('Moved In (UoM)')
                            ->options(function (Get $get) {
                                $itemId = $get('stock_transfer_item_id');
                                if (!$itemId) return [];
                                $item = StockTransferItem::find($itemId);
                                return $item->product?->uoms()
                                            ->whereNotNull('uom_name') // <-- Perbaikan array_flip
                                            ->pluck('uom_name', 'uom_name') ?? [];
                            })
                            ->required()
                            ->default(function (Get $get) {
                                $itemId = $get('stock_transfer_item_id');
                                if (!$itemId) return 'PCS';
                                $item = StockTransferItem::find($itemId);
                                return $item->product?->base_uom ?? 'PCS';
                            }),
                        TextInput::make('input_quantity')
                            ->label('Quantity Moved')
                            ->numeric()->nullable()->autofocus()->required(),

                        Select::make('destination_location_id')
                            ->label('Destination Location')
                            ->searchable()
                            ->options(fn (Get $get) => $this->getDestinationLocationOptions($get('stock_transfer_item_id')))
                            ->required(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $item = StockTransferItem::find($data['stock_transfer_item_id']);
                        $item->loadMissing('product.uoms');

                        // Logika konversi & validasi
                        $inputQty = (float)($data['input_quantity'] ?? 0);
                        $inputUomName = $data['input_uom'] ?? null;
                        $uomData = $item->product?->uoms->where('uom_name', $inputUomName)->first();
                        if (!$uomData) throw new \Exception("UoM {$inputUomName} not found.");
                        $conversionRate = $uomData->conversion_rate ?? 1;
                        $calculatedQtyMoved = $inputQty * $conversionRate;

                        $remainingQtyBase = $this->getRemainingQuantityToPutAway($item, false, true); // (item, false, true=raw float)
                        if (round($calculatedQtyMoved, 5) > round($remainingQtyBase, 5)) {
                             throw ValidationException::withMessages([
                                 'input_quantity' => "Qty moved ({$calculatedQtyMoved} base) cannot exceed remaining qty ({$remainingQtyBase} base)."
                             ]);
                        }

                        $data['stock_transfer_id'] = $item->stock_transfer_id;
                        $data['product_id'] = $item->product_id;
                        $data['quantity_moved'] = $calculatedQtyMoved;
                        $data['user_id'] = Auth::id();

                        unset($data['input_uom'], $data['input_quantity']);
                        return $data;
                    })
                    // ==========================================================
                    // --- ALTERNATIF: Force Full Page Reload ---
                    // (Menghindari bug array_flip & merefresh data 'Remaining Work')
                    // ==========================================================
                    ->successRedirectUrl(fn () => \App\Filament\Resources\WarehouseTaskResource::getUrl('edit', ['record' => $this->getOwnerRecord()]))
                    // ->after(function () {
                    //     // Dihapus untuk menghindari error array_flip
                    // }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->visible($canModify)
                    ->successRedirectUrl(fn () => \App\Filament\Resources\WarehouseTaskResource::getUrl('edit', ['record' => $this->getOwnerRecord()])),
            ])
            ->bulkActions([
                //
            ]);
    }


    /**
     * Helper untuk mendapatkan Sisa Kuantitas
     */
    private function getRemainingQuantityToPutAway(Model|int $item, bool $simpleString, bool $rawFloat = false): float|string
    {
        if (!$item instanceof StockTransferItem) {
            $item = StockTransferItem::find($item);
        }
        if (!$item) return $rawFloat ? 0.0 : ($simpleString ? "0" : "N/A");

        $item->loadMissing('product.uoms', 'putAwayEntries', 'product.baseUomModel');

        // 1. Hitung total yang diminta (dalam base UoM)
        $reqUom = $item->product?->uoms->where('uom_name', $item->uom)->first();
        if (!$reqUom) {
             Log::error("UoM {$item->uom} not found for product {$item->product_id} in ST Item {$item->id}");
             $totalRequiredBase = (float) $item->quantity; // Fallback
        } else {
             $totalRequiredBase = (float)$item->quantity * ($reqUom->conversion_rate ?? 1);
        }

        // 2. Hitung total yang sudah di-log
        $totalMoved = (float)$item->putAwayEntries->sum('quantity_moved');

        // 3. Hitung sisa
        $remainingQtyBase = max(0, $totalRequiredBase - $totalMoved);

        // 4. Kembalikan raw float jika diminta
        if ($rawFloat) {
            return $remainingQtyBase;
        }

        // 5. Format output string berdasarkan $displayUom
        $baseUomName = $item->product?->baseUomModel?->uom_name ?? $item->product?->base_uom ?? 'PCS';

        // Format Cepat (untuk Opsi Select)
        if ($simpleString) {
             return "{$remainingQtyBase} {$baseUomName}";
        }

        // Format Detail (untuk Placeholder)
        $requestedString = "{$item->quantity} {$item->uom}";
        $baseString = "({$totalRequiredBase} {$baseUomName})";

        $fullString = "Requested: {$requestedString} {$baseString}\n";
        $fullString .= "Logged: {$totalMoved} {$baseUomName}\n";
        $fullString .= "Remaining: {$remainingQtyBase} {$baseUomName}";

        // Format Sisa Qty dengan logika UoM Display
        if ($this->displayUom !== 'base' && $this->displayUom !== $baseUomName) {
            $targetUom = $item->product?->uoms->where('uom_name', $this->displayUom)->first();

            if ($targetUom && ($conversionRate = (int)$targetUom->conversion_rate) > 1) {
                $wholeUnits = floor($remainingQtyBase / $conversionRate);
                $remainder = $remainingQtyBase % $conversionRate;

                $formattedRemaining = "";
                if ($wholeUnits > 0) $formattedRemaining .= "{$wholeUnits} {$this->displayUom} ";
                if ($remainder > 0) $formattedRemaining .= "{$remainder} {$baseUomName}";
                if (empty($formattedRemaining)) $formattedRemaining = "0 {$baseUomName}";

                $fullString = "Requested: {$requestedString} {$baseString}\n";
                $fullString .= "Logged: {$totalMoved} {$baseUomName}\n";
                $fullString .= "Remaining: " . trim($formattedRemaining);
            }
        }

        return $fullString;
    }

    /**
     * Helper untuk mendapatkan Opsi Lokasi Tujuan
     */
    private function getDestinationLocationOptions(int|null $stockTransferItemId): array
    {
        if (!$stockTransferItemId) return [];
        $item = StockTransferItem::find($stockTransferItemId);
        if (!$item) return [];

        $item->loadMissing('product');
        $product = $item->product;

        // 1. Tentukan Tipe Warehouse Target (PETA TIPE)
        $productType = $product?->product_type;
        $targetWarehouseTypes = [];
        if ($productType === 'raw_material') $targetWarehouseTypes = ['RAW_MATERIAL', 'COLD_STORAGE'];
        elseif ($productType === 'finished_good') $targetWarehouseTypes = ['FINISHED_GOOD', 'DISTRIBUTION'];
        elseif ($productType === 'merchandise') $targetWarehouseTypes = ['MERCHANDISE', 'FINISHED_GOOD', 'GENERAL'];
        else $targetWarehouseTypes = ['RAW_MATERIAL', 'COLD_STORAGE', 'FINISHED_GOOD', 'MERCHANDIS' , 'GENERAL', 'MAIN'];

        // 2. Ambil Plant ID dari Stock Transfer (Put-Away Task)
        $stockTransfer = $this->getOwnerRecord();
        $plantId = $stockTransfer->plant_id;
        if (!$plantId) {
            Log::error("Put-Away Task {$stockTransfer->id} has no Plant ID. Cannot filter locations.");
            return [];
        }

        // 3. Cari Warehouse ID
        $targetWarehouseIds = Warehouse::where('plant_id', $plantId)
                            ->whereIn('type', $targetWarehouseTypes)
                            ->where('status', true)->pluck('id');
        if ($targetWarehouseIds->isEmpty()) {
            Log::warning("No active warehouses found in Plant ID {$plantId} with types: " . implode(', ', $targetWarehouseTypes));
        }

        // 4. Tentukan Zona Target
        $storageCondition = $product?->storage_condition;
        $targetZoneCodes = [];
        if ($storageCondition === 'COLD') $targetZoneCodes = ['COLD'];
        elseif ($productType === 'raw_material') $targetZoneCodes = ['RM', 'GEN'];
        elseif ($productType === 'finished_good') $targetZoneCodes = ['FG', 'FAST', 'LINE-A', 'MCH', 'GEN'];
        else $targetZoneCodes = ['GEN'];
        $targetZoneIds = Zone::whereIn('code', $targetZoneCodes)->pluck('id');

        // 5. Query Opsi Lokasi (HANYA 'Bin/Rak')
        return Location::whereIn('locatable_id', $targetWarehouseIds)
                        ->where('locatable_type', Warehouse::class)
                        ->where('is_sellable', true) // Ini berarti 'Bin/Rak'
                        ->where('status', true)
                        ->whereIn('zone_id', $targetZoneIds)
                        ->whereNotNull('name') // <-- Perbaikan array_flip
                        ->pluck('name', 'id')
                        ->toArray();
    }
}
