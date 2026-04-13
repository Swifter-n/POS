<?php

namespace App\Filament\Resources\StockTransferResource\RelationManagers;

use App\Models\Inventory;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action as ActionsAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    use HasPermissionChecks;
    protected static string $relationship = 'items';

     public ?string $displayUom = null;
    public function mount(): void
    {
        // (dd() debugging dihapus)
        $this->displayUom = 'base';
    }

    // Cek apakah form/tabel boleh dimodifikasi
    protected function canModify(): bool
    {
        $record = $this->getOwnerRecord();
        $user = Auth::user();
        if (!$user) return false;
        return $record->status === 'draft'
               && $this->check($user, 'create stock transfers');
    }

    // (Hook mutateFormDataBeforeFill Anda sudah benar)
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data['quantity_picked']) && isset($data['product_id'])) {
            $product = Product::find($data['product_id']);
            $baseUomName = $product?->base_uom ?? 'PCS';
            $data['actual_qty'] = $data['quantity_picked'];
            $data['actual_uom'] = $baseUomName;
        }
        return $data;
    }

    /**
     * Helper Konversi.
     * (Kita masih bisa menggunakan ini, tapi kita panggil dari action)
     */
    protected function convertActualQtyToPicked(array $data): array
    {
        Log::info('[ItemsRelationManager] Helper convertActualQtyToPicked dipanggil. Data diterima:', $data);

        // (Perbaikan typo Anda sudah benar)
        if (isset($data['actual_qty']) && isset($data['actual_uom']) && isset($data['product_id'])) {

            // ==========================================================
            // --- [PERBAIKAN BUG SILENT CRASH] ---
            // ==========================================================
            // Muat relasi 'uoms' untuk menghindari crash 'null->uoms'
            $product = Product::with('uoms')->find($data['product_id']);
            // ==========================================================

            if (!$product) {
                Log::warning("[ItemsRelationManager] Konversi GAGAL. Produk ID {$data['product_id']} tidak ditemukan.");
                return $data;
            }

            $uom = $product->uoms->where('uom_name', $data['actual_uom'])->first();
            if (!$uom) {
                 Log::warning("[ItemsRelationManager] Konversi GAGAL. UoM '{$data['actual_uom']}' tidak ditemukan di Produk ID {$data['product_id']}.");
                 // Fallback: Asumsikan rate 1 jika UoM tidak ditemukan (misal: 'PCS' tidak ada di tabel uoms)
                 $conversionRate = 1;
            } else {
                $conversionRate = $uom->conversion_rate ?? 1;
            }

            $quantity = (float) $data['actual_qty'];
            $calculatedValue = $quantity * $conversionRate;
            $data['quantity_picked'] = (int) round($calculatedValue);

            Log::info("[ItemsRelationManager] Konversi berhasil. Nilai quantity_picked: " . $data['quantity_picked']);

            unset($data['actual_qty']);
            unset($data['actual_uom']);
        } else {
            Log::warning("[ItemsRelationManager] Konversi GAGAL. Field 'actual_qty', 'actual_uom', atau 'product_id' tidak ditemukan.");
        }
        return $data;
    }

    // ==========================================================
    // --- [HAPUS] Hook lifecycle tidak dipanggil ---
    // ==========================================================
    // protected function mutateFormDataBeforeCreate(array $data): array
    // { ... }

    // protected function mutateFormDataBeforeSave(array $data): array
    // { ... }


    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name', modifyQueryUsing: function (Builder $query, RelationManager $livewire) {
                        // (Logika relationship Anda sudah benar)
                        $record = $livewire->getOwnerRecord();
                        $plantId = $record->plant_id ?? $record->source_plant_id;
                        if ($plantId) {
                            $locationIds = Location::whereHasMorph('locatable',
                                [Warehouse::class, Outlet::class],
                                function (Builder $query, string $type) use ($plantId) {
                                    if ($type === Warehouse::class) $query->where('plant_id', $plantId);
                                    elseif ($type === Outlet::class) $query->where('supplying_plant_id', $plantId);
                                })
                                ->pluck('id');
                            $productIdsWithStock = Inventory::whereIn('location_id', $locationIds)
                                                   ->where('avail_stock', '>', 0)
                                                   ->distinct()
                                                   ->pluck('product_id');
                            $query->whereIn('id', $productIdsWithStock);
                        } else {
                            $query->whereRaw('0 = 1');
                        }
                        $query->where('business_id', Auth::user()->business_id)
                              ->whereIn('product_type', ['finished_good', 'raw_material', 'merchandise']);
                    })
                    ->searchable()->preload()->required()->reactive()
                    ->columnSpan(4)
                    ->afterStateUpdated(function(Set $set) {
                        $set('uom', null);
                        $set('actual_uom', null);
                    }),

                TextInput::make('quantity')->numeric()->required()->minValue(1)->reactive()
                    ->columnSpan(2),

                Select::make('uom')->label('Unit')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        // ==========================================================
                        // --- [PERBAIKAN BUG] Hapus filter 'uom_type' ---
                        // ==========================================================
                        return ProductUom::where('product_id', $productId)
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()->reactive()
                    ->default(fn(Get $get) => Product::find($get('product_id'))?->base_uom ?? 'PCS')
                    ->columnSpan(2),

                TextInput::make('actual_qty') // Field Virtual 1
                    ->label('Actual Qty Moved')
                    ->numeric()
                    ->default(0)
                    ->visible(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->transfer_type === 'internal')
                    ->required(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->transfer_type === 'internal')
                    ->columnSpan(4),

                Select::make('actual_uom') // Field Virtual 2
                    ->label('Actual UoM')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        // ==========================================================
                        // --- [PERBAIKAN BUG] Hapus filter 'uom_type' ---
                        // ==========================================================
                        return ProductUom::where('product_id', $productId)
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->default(function (Get $get) {
                        $product = Product::find($get('product_id'));
                        return $product?->base_uom;
                    })
                    ->visible(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->transfer_type === 'internal')
                    ->required(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->transfer_type === 'internal')
                    ->searchable()
                    ->preload()
                    ->columnSpan(4),
            ])
            ->columns(8);
    }

    public function table(Table $table): Table
    {
        $canModify = $this->canModify();
        $isInternalTransfer = $this->getOwnerRecord()->transfer_type === 'internal';

        return $table
            ->recordTitleAttribute('product.name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.uoms']))
            ->columns([
                TextColumn::make('product.name')->searchable(),
                 TextColumn::make('quantity')
                    ->label('Qty Requested')
                    // ... (Logika formatStateUsing Anda sudah benar)
                    ->badge()
                    ->color('secondary')
                    ->formatStateUsing(function ($state, Model $itemRecord): string {
                        $qtyRequested = (int) $state;
                        $requestUomName = $itemRecord->uom;
                        $itemRecord->loadMissing('product.uoms');
                        $baseUomName = $itemRecord->product?->base_uom ?? 'PCS';
                        if ($this->displayUom === 'base' || $this->displayUom === $requestUomName || !$itemRecord->product) {
                            if ($requestUomName === $baseUomName || $this->displayUom === $baseUomName || $this->displayUom === 'base') {
                                $reqUom = $itemRecord->product?->uoms->where('uom_name', $requestUomName)->first();
                                $qtyInBase = $qtyRequested * ($reqUom?->conversion_rate ?? 1);
                                return "{$qtyInBase} {$baseUomName}";
                            } else {
                                return "{$qtyRequested} {$requestUomName}";
                            }
                        }
                        $reqUom = $itemRecord->product?->uoms->where('uom_name', $requestUomName)->first();
                        $qtyInBase = $qtyRequested * ($reqUom?->conversion_rate ?? 1);
                        $targetUom = $itemRecord->product?->uoms->where('uom_name', $this->displayUom)->first();
                        if ($targetUom && ($conversionRate = (int)$targetUom->conversion_rate) > 1) {
                            $wholeUnits = floor($qtyInBase / $conversionRate);
                            $remainder = $qtyInBase % $conversionRate;
                            if ($remainder == 0) return "{$wholeUnits} {$this->displayUom}";
                            elseif ($wholeUnits == 0) return "{$remainder} {$baseUomName}";
                            else return "{$wholeUnits} {$this->displayUom} + {$remainder} {$baseUomName}";
                        }
                        return "{$qtyInBase} {$baseUomName}";
                    }),
                TextColumn::make('quantity_picked')
                    ->label('Actual Qty Moved')
                    // ... (Logika formatStateUsing Anda sudah benar)
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(function ($state, Model $itemRecord): string {
                        $baseUomName = $itemRecord->product?->base_uom ?? 'PCS';
                        $qty = $state ?? 0;
                        return "{$qty} {$baseUomName}";
                    })
                    ->visible($isInternalTransfer),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\Action::make('changeDisplayUom')
                    ->label('Display Qty In')
                    // ... (Logika Action Anda sudah benar)
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

                // ==========================================================
                // --- SOLUSI ALTERNATIF: Pindahkan Logika ke Sini ---
                // ==========================================================
                Tables\Actions\CreateAction::make()
                    ->visible($canModify)
                    // Hook 'Create' (Aksi Tombol "Create Item")
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['actual_qty'] = $data['quantity'] ?? 0;
                        $data['actual_uom'] = $data['uom'] ?? 'PCS';
                        // ----------------------------------------------------
                        Log::info('[ItemsRelationManager] CreateAction->mutateFormDataUsing dipanggil.');
                        // 'Create' $data sudah lengkap, panggil helper
                        return $this->convertActualQtyToPicked($data);
                    })
            ])
            ->actions([
                 // ==========================================================
                // --- SOLUSI ALTERNATIF: Pindahkan Logika ke Sini ---
                // ==========================================================
                 Tables\Actions\EditAction::make()
                    ->label('Edit Item')
                    ->visible($canModify)
                    // Hook 'Edit' (Aksi Tombol "Save")
                    ->mutateFormDataUsing(function (Model $record, array $data): array {
                        Log::info('[ItemsRelationManager] EditAction->mutateFormDataUsing dipanggil.');

                        // 1. $data hanya berisi field yang berubah
                        // 2. $record berisi data lama dari database
                        // 3. Kita gabungkan keduanya untuk mendapatkan data lengkap
                        $allData = array_merge($record->toArray(), $data);

                        // 4. Kita juga perlu field 'virtual' (yang tidak ada di $record)
                        //    Jika 'actual_qty' atau 'actual_uom' tidak ada di $data (tidak berubah)
                        //    kita ambil dari 'mutateFormDataBeforeFill'
                        if (!isset($allData['actual_qty']) || !isset($allData['actual_uom'])) {
                             // Panggil 'mutateFormDataBeforeFill' secara manual
                             $filledData = $this->mutateFormDataBeforeFill($record->toArray());
                             $allData['actual_qty'] = $allData['actual_qty'] ?? $filledData['actual_qty'];
                             $allData['actual_uom'] = $allData['actual_uom'] ?? $filledData['actual_uom'];
                        }

                        // 5. Panggil helper dengan data lengkap
                        $convertedData = $this->convertActualQtyToPicked($allData);

                        // 6. Kembalikan HANYA data yang berubah + hasil konversi
                        $data['quantity_picked'] = $convertedData['quantity_picked'] ?? null;
                        unset($data['actual_qty']);
                        unset($data['actual_uom']);

                        return $data;
                    }),

                 Tables\Actions\DeleteAction::make()
                     ->visible($canModify),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ])->visible($canModify),
            ]);
    }
}
