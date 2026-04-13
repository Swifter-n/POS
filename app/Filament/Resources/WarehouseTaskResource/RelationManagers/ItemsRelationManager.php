<?php

namespace App\Filament\Resources\WarehouseTaskResource\RelationManagers;

use App\Models\Location;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
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

class ItemsRelationManager extends RelationManager
{
    use HasPermissionChecks;
    protected static string $relationship = 'items';


    // State untuk Display UoM
    public ?string $displayUom = null;
    public function mount(): void
    {
        $this->displayUom = 'base';
    }

    // Helper cek apakah form boleh diedit
    protected function canModify(): bool
    {
        $record = $this->getOwnerRecord(); // Ini adalah StockTransfer (PA-...)
        $user = Auth::user();
        if (!$user) return false;
        // Cek status, user assignment, dan permission
        return $record->status === 'pending_pick'
               && $record->assigned_user_id == $user->id
               && $this->check($user, 'execute putaway tasks'); // Ganti permission jika perlu
    }

    // Form() utama tidak digunakan
    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        $canModify = $this->canModify();

        return $table
            // ->recordTitleAttribute('product.name')
            // ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.uoms', 'destinationLocation']))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty Task')
                    ->formatStateUsing(fn ($state, Model $record) => $state . ' ' . $record->uom),

                // --- KOLOM BARU: SUGGESTED LOCATION (HASIL ALGORITMA) ---
                Tables\Columns\TextColumn::make('suggestedLocation.name')
                    ->label('System Suggestion')
                    ->icon('heroicon-m-map-pin')
                    ->color('info')
                    ->description(fn (Model $record) => $record->suggestedLocation ? "Zone: " . $record->suggestedLocation->zone->name : 'No suggestion')
                    ->placeholder('Manual Decision Needed'),

                // Progress Bar sederhana
                Tables\Columns\TextColumn::make('putAwayEntries_sum_quantity_moved')
                    ->label('Moved So Far')
                    ->sum('putAwayEntries', 'quantity_moved')
                    ->formatStateUsing(fn ($state, Model $record) => ($state ?? 0) . ' / ' . $record->quantity),
                // TextColumn::make('product.name')->searchable(),

                // // Kolom Qty Requested (dengan mixed UoM)
                //  TextColumn::make('quantity')
                //     ->label('Qty to Put-Away')
                //     ->badge()
                //     ->color('secondary')
                //     ->formatStateUsing(function ($state, Model $itemRecord): string {
                //         // ==========================================================
                //         // --- LOGIKA LENGKAP MIXED UOM ---
                //         // ==========================================================
                //         $qtyRequested = (int) $state;
                //         $requestUomName = $itemRecord->uom;
                //         $itemRecord->loadMissing('product.uoms');
                //         $baseUomName = $itemRecord->product?->base_uom ?? 'PCS';

                //         if ($this->displayUom === 'base' || $this->displayUom === $requestUomName || !$itemRecord->product) {
                //             if ($requestUomName === $baseUomName || $this->displayUom === $baseUomName || $this->displayUom === 'base') {
                //                 $reqUom = $itemRecord->product?->uoms->where('uom_name', $requestUomName)->first();
                //                 $qtyInBase = $qtyRequested * ($reqUom?->conversion_rate ?? 1);
                //                 return "{$qtyInBase} {$baseUomName}";
                //             } else {
                //                 return "{$qtyRequested} {$requestUomName}";
                //             }
                //         }
                //         $reqUom = $itemRecord->product?->uoms->where('uom_name', $requestUomName)->first();
                //         $qtyInBase = $qtyRequested * ($reqUom?->conversion_rate ?? 1);
                //         $targetUom = $itemRecord->product?->uoms->where('uom_name', $this->displayUom)->first();
                //         if ($targetUom && ($conversionRate = (int)$targetUom->conversion_rate) > 1) {
                //             $wholeUnits = floor($qtyInBase / $conversionRate);
                //             $remainder = $qtyInBase % $conversionRate;
                //             if ($remainder == 0) return "{$wholeUnits} {$this->displayUom}";
                //             elseif ($wholeUnits == 0) return "{$remainder} {$baseUomName}";
                //             else return "{$wholeUnits} {$this->displayUom} + {$remainder} {$baseUomName}";
                //         }
                //         return "{$qtyInBase} {$baseUomName}";
                //         // ==========================================================
                //     }),

                // // Kolom Qty Picked (hanya untuk Put-Away)
                // TextColumn::make('quantity_picked')
                //     ->label('Qty Moved (Base UoM)')
                //     ->badge()
                //     // Cek Qty Base (dari helper) vs Qty Picked
                //     ->color(fn ($state, Model $itemRecord) => $state === null ? 'danger' : ((float)$state < $this->getItemQtyInBaseUom($itemRecord) ? 'warning' : 'success'))
                //     ->formatStateUsing(fn ($state, Model $itemRecord) => $state === null ? 'Not Confirmed' : $state . ' ' . ($itemRecord->product?->base_uom ?? 'PCS'))
                //     ,

                // TextColumn::make('destinationLocation.name')
                //     ->label('Destination'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('changeDisplayUom') // (Action Display UoM)
                    ->label('Display Qty In')
                    ->icon('heroicon-o-cube')->color('gray')
                    ->form(function ($livewire) { // Terima $livewire
                        // ==========================================================
                        // --- LOGIKA LENGKAP UOM FORM ---
                        // ==========================================================
                        return [
                            Select::make('displayUomSelect')
                                ->label('Select Unit of Measure')
                                ->live()
                                ->options(function () use ($livewire): array {
                                    $stockTransfer = $livewire->getOwnerRecord()->load('items.product.uoms');
                                    $availableUoms = $stockTransfer->items
                                        ->pluck('product.uoms')->flatten()->whereNotNull()
                                        ->pluck('uom_name')->unique()->sort()->values()->all();
                                    $options = ['base' => 'Base UoM'];
                                    foreach ($availableUoms as $uom) {
                                        $baseUomName = $stockTransfer->items->first()?->product?->base_uom ?? 'PCS';
                                        if ($uom !== $baseUomName) $options[$uom] = $uom;
                                    }
                                    return $options;
                                })
                                ->default($livewire->displayUom) // Akses properti
                                ->afterStateUpdated(fn ($state) => $livewire->displayUom = $state), // Set properti
                        ];
                        // ==========================================================
                    })
                    ->action(null), // Action null karena hanya ubah state

                // Hapus CreateAction
            ])
            ->actions([
                 // ==========================================================
                 // --- HANYA ADA ACTION UNTUK INPUT PUT-AWAY ---
                 // ==========================================================
                 Tables\Actions\EditAction::make('input_putaway')
                    ->label('Input Actual Qty/Dest')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible($canModify) // <-- Hanya untuk Put-Away
                    // Form Kustom (Logika "Pintar")
                    ->form(function (Model $itemRecord): array {
                        $itemRecord->loadMissing('product');

                        // 1. Tentukan Tipe Warehouse Target (PETA TIPE)
                        $productType = $itemRecord->product?->product_type;
                        $targetWarehouseTypes = [];
                        if ($productType === 'raw_material') $targetWarehouseTypes = ['RAW_MATERIAL', 'COLD_STORAGE'];
                        elseif ($productType === 'finished_good') $targetWarehouseTypes = ['FINISHED_GOOD', 'DISTRIBUTION'];
                        elseif ($productType === 'merchandise') $targetWarehouseTypes = ['MERCHANDISE', 'FINISHED_GOOD', 'GENERAL'];
                        else $targetWarehouseTypes = ['RAW_MATERIAL', 'COLD_STORAGE', 'FINISHED_GOOD', 'MERCHANDISE', 'GENERAL', 'MAIN'];

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
                        $product = $itemRecord->product;
                        $storageCondition = $product?->storage_condition;
                        $targetZoneCodes = [];
                        if ($storageCondition === 'COLD') $targetZoneCodes = ['COLD'];
                        elseif ($productType === 'raw_material') $targetZoneCodes = ['RM', 'GEN'];
                        elseif ($productType === 'finished_good') $targetZoneCodes = ['FG', 'FAST', 'LINE-A', 'MCH', 'GEN'];
                        else $targetZoneCodes = ['GEN'];
                        $targetZoneIds = Zone::whereIn('code', $targetZoneCodes)->pluck('id');

                        // 5. Query Opsi Lokasi
                        $locationOptions = Location::whereIn('locatable_id', $targetWarehouseIds)
                                ->where('locatable_type', Warehouse::class)
                                ->where('is_sellable', true)
                                ->where('status', true)
                                ->whereIn('zone_id', $targetZoneIds)
                                ->pluck('name', 'id');

                        // 6. Kembalikan Schema Form
                        return [
                            Placeholder::make('product_name')->content($itemRecord->product?->name),
                            Placeholder::make('quantity_requested')->content($itemRecord->quantity . ' ' . $itemRecord->uom),
                            Select::make('input_uom')
                                ->label('Moved In (UoM)')
                                ->options(fn() => $itemRecord->product?->uoms()->pluck('uom_name', 'uom_name') ?? [])
                                ->required()
                                ->default($itemRecord->product?->base_uom ?? 'PCS'),
                            TextInput::make('input_quantity')
                                ->label('Quantity Moved')
                                ->numeric()->nullable()->autofocus()->required(),
                             Select::make('destination_location_id')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) =>
                                    Location::where('name', 'like', "%{$search}%")
                                        ->where('is_sellable', true)
                                        // ... filter warehouse/plant ID ...
                                        ->limit(50)->pluck('name', 'id')
                                )
                        ];
                    })
                    // Mutasi data (konversi UoM) sebelum simpan
                    ->mutateFormDataUsing(function (array $data, Model $itemRecord): array {
                        $itemRecord->loadMissing('product.uoms');
                        $inputQty = (float)($data['input_quantity'] ?? 0);
                        $inputUomName = $data['input_uom'] ?? null;

                        $uomData = $itemRecord->product?->uoms->where('uom_name', $inputUomName)->first();
                        if (!$uomData) throw new \Exception("UoM {$inputUomName} not found.");
                        $conversionRate = $uomData->conversion_rate ?? 1;
                        $calculatedQtyPicked = $inputQty * $conversionRate;

                        $allocatedQtyBase = $this->getItemQtyInBaseUom($itemRecord); // Panggil helper

                        if (round($calculatedQtyPicked, 5) > round($allocatedQtyBase, 5)) {
                             throw ValidationException::withMessages([
                                 'input_quantity' => "Qty moved ({$calculatedQtyPicked} base) cannot exceed requested qty ({$allocatedQtyBase} base)."
                             ]);
                        }

                        $data['quantity_picked'] = $calculatedQtyPicked;
                        unset($data['input_uom'], $data['input_quantity']);
                        return $data;
                     })
                     // ==========================================================
                     // --- LOGIKA LENGKAP fillForm ---
                     // ==========================================================
                     ->fillForm(function (Model $itemRecord): array {
                         $itemRecord->loadMissing('product.uoms'); // Muat UoMs
                         $defaultUom = $itemRecord->product?->base_uom ?? 'PCS';
                         $qtyPickedBase = $itemRecord->quantity_picked; // Qty Base UoM yg tersimpan
                         $defaultQtyInput = 0;

                         // Jika sudah ada data tersimpan (quantity_picked tidak null)
                         if ($qtyPickedBase !== null) {
                             // Coba konversi kembali ke UoM request asli (jika ada)
                             $requestedUomName = $itemRecord->uom;
                             $requestedUomData = $itemRecord->product?->uoms->where('uom_name', $requestedUomName)->first();

                             if ($requestedUomData && $requestedUomData->conversion_rate > 0) {
                                 // Tampilkan dalam UoM request
                                 $defaultQtyInput = round($qtyPickedBase / $requestedUomData->conversion_rate, 4);
                                 $defaultUom = $requestedUomName;
                             } else {
                                 // Jika gagal konversi (atau UoM request = base), tampilkan base
                                 $defaultQtyInput = $qtyPickedBase;
                             }
                         }

                         return [
                             'input_uom' => $defaultUom,
                             'input_quantity' => $defaultQtyInput,
                             'destination_location_id' => $itemRecord->destination_location_id,
                             'quantity_picked' => $itemRecord->quantity_picked,
                         ];
                     }),
                 // ==========================================================

                 // Hapus DeleteAction untuk PutAway
            ])
            ->bulkActions([
                // Hapus Bulk Actions
            ]);
    }

    /**
     * Helper untuk mendapatkan Qty Request dalam Base UoM.
     */
    private function getItemQtyInBaseUom(Model $itemRecord): float
    {
        $itemRecord->loadMissing('product.uoms');
        $reqUom = $itemRecord->product?->uoms->where('uom_name', $itemRecord->uom)->first();
        if (!$reqUom) {
             Log::error("UoM {$itemRecord->uom} not found for product {$itemRecord->product_id} in ST Item {$itemRecord->id}");
             return (float) $itemRecord->quantity; // Fallback
        }
        return (float)$itemRecord->quantity * ($reqUom->conversion_rate ?? 1);
    }
}
