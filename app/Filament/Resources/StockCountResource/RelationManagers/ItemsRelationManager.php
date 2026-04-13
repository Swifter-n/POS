<?php

namespace App\Filament\Resources\StockCountResource\RelationManagers;

use App\Models\Outlet;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;


class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Count Items & Validation';

    public ?string $displayUom = 'base';

    // =========================================================================
    // HELPER: Cek Konteks (Warehouse vs Outlet)
    // =========================================================================
    private function isWarehouseCount(): bool
    {
        return $this->getOwnerRecord()->countable_type === Warehouse::class;
    }

    private function isOutletCount(): bool
    {
        return $this->getOwnerRecord()->countable_type === Outlet::class;
    }

    // =========================================================================
    // FORM (Digunakan oleh EditAction standar - Koreksi Manager)
    // =========================================================================
    public function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(2)->schema([
                Forms\Components\Placeholder::make('product_info')
                    ->label('Product')
                    ->content(fn(Model $record) => $record->product?->name . ' (' . $record->product?->sku . ')')
                    ->columnSpanFull(),

                Checkbox::make('is_zero_count')
                    ->label('Mark as Zero Count')
                    ->live()
                    ->afterStateUpdated(function (Set $set, Model $record, $state) {
                        if ($state === true) {
                            $set('final_counted_stock', 0);
                            $record->loadMissing('product');
                            $set('final_counted_uom', $record->product?->base_uom ?? 'pcs');
                        }
                    })
                    ->default(fn (Model $record): bool => (bool)$record->is_zero_count)
                    ->columnSpanFull(),

                TextInput::make('final_counted_stock')
                    ->label('Final Count (Correction)')
                    ->numeric()
                    ->required()
                    ->disabled(fn (Get $get) => $get('is_zero_count') === true)
                    ->columnSpan(1),

                Select::make('final_counted_uom')
                    ->label('UoM')
                    ->options(fn(Model $record) => $record->product?->uoms->pluck('uom_name', 'uom_name')->toArray() ?? [])
                    ->default(fn(Model $record) => $record->final_counted_uom ?? $record->product?->base_uom)
                    ->disabled(fn (Get $get) => $get('is_zero_count') === true)
                    ->required(fn (Get $get) => $get('is_zero_count') !== true)
                    ->columnSpan(1),
            ]),
        ]);
    }

    // =========================================================================
    // TABLE
    // =========================================================================
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.uoms', 'inventory', 'entries']))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Model $record) => $record->inventory?->batch ? "Batch: " . $record->inventory->batch : null),

                // --------------------------------------------------------
                // 1. SYSTEM STOCK (Dilindungi Logic Blind Count)
                // --------------------------------------------------------
                Tables\Columns\TextColumn::make('system_stock')
                    ->label('System Snapshot')
                    ->badge()
                    ->color('gray')
                    ->getStateUsing(function (Model $record, RelationManager $livewire) {
                        $record->loadMissing('product.uoms');
                        $product = $record->product;
                        if (!$product) return 'N/A';

                        $systemStockBase = (float)$record->system_stock;
                        $baseUomName = $product->base_uom ?? 'pcs';
                        $displayUom = $livewire->displayUom ?? 'base';

                        // Logika Tampilan UoM
                        if ($displayUom === 'base' || strcasecmp($displayUom, $baseUomName) === 0) {
                            return $systemStockBase . ' ' . $baseUomName;
                        }

                        $uomData = $product->uoms->where('uom_name', $displayUom)->first();
                        $conversionRate = $uomData?->conversion_rate ?? 1;
                        if ($conversionRate <= 0) $conversionRate = 1;

                        $convertedQty = $systemStockBase / $conversionRate;
                        return number_format($convertedQty, 2, '.', '') . ' ' . $displayUom;
                    })
                    // VISIBILITY LOGIC:
                    // - Outlet: Selalu Muncul (Kasir perlu tau)
                    // - Warehouse: HANYA Muncul jika status sudah selesai hitung (pending_validation/posted)
                    ->visible(fn () =>
                        !$this->isWarehouseCount() ||
                        in_array($this->getOwnerRecord()->status, ['pending_validation', 'posted'])
                    ),

                // --------------------------------------------------------
                // 2. TEAM COUNTS (Khusus Warehouse)
                // --------------------------------------------------------
                Tables\Columns\TextColumn::make('team_yellow_count')
                    ->label('Yellow Team')
                    ->getStateUsing(fn (Model $record) => $this->getTeamCountDisplay($record, 'Kuning'))
                    ->visible(fn () => $this->isWarehouseCount()),

                Tables\Columns\TextColumn::make('team_green_count')
                    ->label('Green Team')
                    ->getStateUsing(fn (Model $record) => $this->getTeamCountDisplay($record, 'Hijau'))
                    ->visible(fn () => $this->isWarehouseCount()),

                // --------------------------------------------------------
                // 3. FINAL COUNT (Hasil Akhir)
                // --------------------------------------------------------
                // Tables\Columns\TextColumn::make('final_counted_stock')
                //     ->label('Actual Count')
                //     ->weight('bold')
                //     ->formatStateUsing(function (Model $record) {
                //         if ($record->final_counted_stock === null) return '-';
                //         return (float)$record->final_counted_stock . ' ' . ($record->final_counted_uom ?? 'PCS');
                //     }),

                Tables\Columns\TextColumn::make('final_counted_stock')
                    ->label('Actual Count (Final)')
                    ->weight('bold')
                    ->formatStateUsing(function (Model $record) {
                        // Jika Final Count sudah diisi Manager
                        if ($record->final_counted_stock !== null) {
                            return (float)$record->final_counted_stock . ' ' . ($record->final_counted_uom ?? 'PCS');
                        }

                        // Jika belum diisi Manager, tapi Tim sudah input
                        $hasEntry = $record->entries()->exists();
                        if ($hasEntry) {
                            return 'Needs Validation'; // Indikator visual
                        }

                        return '-';
                    })
                    ->color(fn (string $state) => $state === 'Needs Validation' ? 'warning' : 'gray')
                    ->icon(fn (string $state) => $state === 'Needs Validation' ? 'heroicon-o-exclamation-circle' : null),

                // --------------------------------------------------------
                // 4. VARIANCE (Selisih - Dilindungi Logic Blind Count)
                // --------------------------------------------------------
                Tables\Columns\TextColumn::make('variance')
                    ->label('Variance')
                    ->badge()
                    ->state(function (Model $record): float {
                        if ($record->final_counted_stock === null) return 0;

                        $system = (float) $record->system_stock;
                        $counted = (float) $record->final_counted_stock;

                        // Konversi UoM Input ke Base UoM untuk perbandingan akurat
                        $conversionRate = 1;
                        if ($record->final_counted_uom && $record->final_counted_uom !== $record->product->base_uom) {
                             $uomData = $record->product->uoms->where('uom_name', $record->final_counted_uom)->first();
                             $conversionRate = $uomData?->conversion_rate ?? 1;
                        }

                        $countedBase = $counted * $conversionRate;
                        return round($countedBase - $system, 4);
                    })
                    ->color(fn (float $state): string => abs($state) < 0.0001 ? 'success' : 'danger')
                    ->formatStateUsing(function (float $state, Model $record) {
                        $uom = $record->product->base_uom ?? 'PCS';
                        return ($state > 0 ? "+$state" : "$state") . " $uom";
                    })
                    // VISIBILITY LOGIC (Sama dengan System Stock)
                    ->visible(fn () =>
                        !$this->isWarehouseCount() ||
                        in_array($this->getOwnerRecord()->status, ['pending_validation', 'posted'])
                    ),
            ])
            ->filters([
                Tables\Filters\Filter::make('has_variance')
                    ->label('Show Discrepancies Only')
                    ->query(fn (Builder $query) => $query->whereRaw('final_counted_stock != system_stock')),
            ])
            ->headerActions([
                // Aksi Ganti Tampilan UoM (Sama seperti sebelumnya)
                Tables\Actions\Action::make('changeDisplayUom')
                    ->label('Display System Stock In')
                    ->icon('heroicon-o-cube')
                    ->color('gray')
                    ->form(function ($livewire) {
                        return [
                            Select::make('displayUomSelect')
                                ->label('Select Unit')
                                ->live()
                                ->options(function () use ($livewire): array {
                                    $record = $livewire->getOwnerRecord()->load('items.product.uoms');
                                    return $record->items->pluck('product.uoms')->flatten()
                                        ->pluck('uom_name', 'uom_name')->unique()->toArray();
                                })
                                ->default($livewire->displayUom)
                                ->afterStateUpdated(fn ($livewire, $state) => $livewire->displayUom = $state),
                        ];
                    })
                    ->modalSubmitActionLabel('Update View'),
            ])
            ->actions([
                // --------------------------------------------------------
                // ACTION A: INPUT TEAM COUNT (Khusus Warehouse)
                // --------------------------------------------------------
                Tables\Actions\Action::make('inputTeamCount')
                    ->label('Input Count')
                    ->icon('heroicon-o-pencil-square')
                    ->color('info')
                    ->visible(fn () =>
                        $this->isWarehouseCount() &&
                        $this->getOwnerRecord()->status === 'in_progress'
                    )
                    ->mountUsing(function(Form $form, Model $record, RelationManager $livewire) {
                        // Logika deteksi User -> Tim (Kuning/Hijau)
                        $user = Auth::user();
                        $ownerRecord = $livewire->getOwnerRecord();
                        $teams = $ownerRecord->assigned_teams ?? [];

                        $teamName = null;
                        if (in_array($user->id, $teams['yellow'] ?? [])) $teamName = 'Kuning';
                        elseif (in_array($user->id, $teams['green'] ?? [])) $teamName = 'Hijau';

                        $record->loadMissing('entries');
                        $entry = $record->entries->where('team_name', $teamName)->first();
                        $isZero = $entry?->is_zero_count ?? false;

                        $form->fill([
                            'team_name' => $teamName,
                            'counted_quantity' => $entry?->counted_quantity,
                            'counted_uom' => $entry?->counted_uom ?? $record->product?->base_uom,
                            'is_zero_count_team' => $isZero,
                        ]);
                    })
                    ->form([
                        Grid::make(2)->schema([
                            Placeholder::make('info_prod')
                                ->content(fn(Model $record) => $record->product->name)
                                ->columnSpanFull(),

                            Checkbox::make('is_zero_count_team')
                                ->label('Zero Count')
                                ->live()
                                ->afterStateUpdated(fn (Set $set) => $set('counted_quantity', 0)),

                            TextInput::make('counted_quantity')
                                ->label('Qty')
                                ->numeric()->required()
                                ->disabled(fn (Get $get) => $get('is_zero_count_team')),

                            Select::make('counted_uom')
                                ->label('UoM')
                                ->options(fn(Model $r) => $r->product->uoms->pluck('uom_name', 'uom_name'))
                                ->required()
                                ->disabled(fn (Get $get) => $get('is_zero_count_team')),

                            Hidden::make('team_name'),
                        ])
                    ])
                    ->action(function (Model $record, array $data) {
                        $user = Auth::user();
                        if (!$data['team_name']) {
                            Notification::make()->title('You are not assigned to a counting team.')->danger()->send();
                            return;
                        }

                        $record->entries()->updateOrCreate(
                            ['stock_count_item_id' => $record->id, 'team_name' => $data['team_name']],
                            [
                                'user_id' => $user->id,
                                'counted_quantity' => (float)$data['counted_quantity'],
                                'counted_uom' => $data['counted_uom'],
                                'is_zero_count' => (bool)$data['is_zero_count_team'],
                            ]
                        );
                        Notification::make()->title('Count saved')->success()->send();
                    }),

                // --------------------------------------------------------
                // ACTION B: SET FINAL COUNT (Khusus Warehouse Validator)
                // --------------------------------------------------------
                Tables\Actions\EditAction::make('setFinalCount')
                    ->label('Set Final')
                    ->icon('heroicon-o-check')
                    ->visible(fn () =>
                        $this->isWarehouseCount() &&
                        $this->getOwnerRecord()->status === 'pending_validation'
                        // Tambahkan cek user ID jika ingin membatasi validator spesifik
                    )
                    // Menggunakan Schema Form utama di atas
                    ->mountUsing(function (Form $form, Model $record) {
                        $form->fill([
                            'final_counted_stock' => $record->final_counted_stock,
                            'final_counted_uom' => $record->final_counted_uom,
                            'is_zero_count' => (bool)$record->is_zero_count,
                        ]);
                    }),

                // --------------------------------------------------------
                // ACTION C: KOREKSI MANUAL (Warehouse & Outlet)
                // --------------------------------------------------------
                Tables\Actions\EditAction::make('correctCount')
                    ->label('Correct')
                    ->icon('heroicon-o-pencil')
                    ->color('warning')
                    // Hanya muncul saat fase review/approval
                    ->visible(fn () => in_array($this->getOwnerRecord()->status, ['pending_approval', 'pending_validation'])),
            ]);
    }

    // --- Helper Private untuk Tampilan Team ---
    private function getTeamCountDisplay(Model $record, string $teamName): string
    {
        $entry = $record->entries->where('team_name', $teamName)->first();
        if (!$entry) return '-';

        $qty = (float)$entry->counted_quantity;
        return $entry->is_zero_count ? '0 (Zero)' : "$qty {$entry->counted_uom}";
    }
}

// class ItemsRelationManager extends RelationManager
// {
//     use HasPermissionChecks;
//     protected static string $relationship = 'items';

//     public ?string $displayUom = 'base';


//     public function form(Form $form): Form
//     {
//         // Form ini hanya untuk Validator (Tim Putih)
//         return $form->schema([
//             Grid::make(2)
//                 ->schema([
//                     Checkbox::make('is_zero_count')
//                         ->label('Mark as Zero Count')
//                         ->live()
//                         ->afterStateUpdated(function (Set $set, Model $record, $state) {
//                             if ($state === true) {
//                                 $set('final_counted_stock', 0);
//                                 $record->loadMissing('product');
//                                 $set('final_counted_uom', $record->product?->base_uom ?? 'pcs');
//                             }
//                         })
//                         ->default(fn (Model $record): bool => (bool)$record->is_zero_count)
//                         ->columnSpanFull(),

//                     Forms\Components\TextInput::make('final_counted_stock')
//                         ->label('Final Counted Stock (Validator)')
//                         ->numeric()
//                         ->required()
//                         ->helperText('Input hitungan final setelah validasi.')
//                         ->disabled(fn (Get $get) => $get('is_zero_count_validator') === true)
//                         ->columnSpan(1),

//                     Forms\Components\Select::make('final_counted_uom')
//                         ->label('UoM')
//                         ->options(function (Model $record): array {
//                             $record->loadMissing('product.uoms');
//                             return $record->product?->uoms->pluck('uom_name', 'uom_name')->toArray() ?? [];
//                         })
//                         ->default(function (Model $record) {
//                             $record->loadMissing('product');
//                             return $record->final_counted_uom ?? $record->product?->base_uom ?? 'pcs';
//                         })
//                         ->required(fn (Get $get): bool => $get('is_zero_count') !== true)
//                         ->disabled(fn (Get $get) => $get('is_zero_count_validator') === true)
//                         ->columnSpan(1),
//                 ]),
//         ]);
//     }

//     public function table(Table $table): Table
//     {
//         return $table
//             ->recordTitleAttribute('product.name')
//             ->columns([
//             Tables\Columns\TextColumn::make('product.name')->searchable(),
//                 Tables\Columns\TextColumn::make('inventory.batch')->searchable(),

//                 Tables\Columns\TextColumn::make('system_stock')
//                     ->label('System Stock')
//                     ->numeric()
//                     ->getStateUsing(function (Model $record, RelationManager $livewire) {
//                         // ... (Logika konversi UoM Anda sudah benar)
//                         $record->loadMissing('product.uoms');
//                         $product = $record->product;
//                         if (!$product) return 'N/A';
//                         $systemStockBase = (float)$record->system_stock;
//                         $baseUomName = $product->base_uom ?? 'pcs';
//                         $displayUom = $livewire->displayUom ?? 'base';
//                         if ($displayUom === 'base' || strcasecmp($displayUom, $baseUomName) === 0) {
//                             return $systemStockBase . ' ' . $baseUomName;
//                         }
//                         $uomData = $product->uoms->where('uom_name', $displayUom)->first();
//                         $conversionRate = $uomData?->conversion_rate ?? 0;
//                         if ($conversionRate <= 0) {
//                             return $systemStockBase . ' ' . $baseUomName;
//                         }
//                         $convertedQty = $systemStockBase / $conversionRate;
//                         return number_format($convertedQty, 4, '.', '') . ' ' . $displayUom;
//                     }),

//                 Tables\Columns\TextColumn::make('team_yellow_count')
//                     ->label('Count (Tim Kuning)')
//                     ->getStateUsing(function (Model $record) {
//                         $record->loadMissing('entries');
//                         $entry = $record->entries->where('team_name', 'Kuning')->first();
//                         if (!$entry) return 'N/A';
//                         return (float)$entry->counted_quantity . ' ' . strtoupper($entry->counted_uom ?? '');
//                     }),

//                 Tables\Columns\TextColumn::make('team_green_count')
//                     ->label('Count (Tim Hijau)')
//                     ->getStateUsing(function (Model $record) {
//                         $record->loadMissing('entries');
//                         $entry = $record->entries->where('team_name', 'Hijau')->first();
//                         if (!$entry) return 'N/A';
//                         return (float)$entry->counted_quantity . ' ' . strtoupper($entry->counted_uom ?? '');
//                     }),

//                 Tables\Columns\TextColumn::make('final_counted_stock')
//                     ->label('Final Count')
//                     ->numeric(),

//                 Tables\Columns\TextColumn::make('final_counted_uom')
//                     ->label('UoM')
//                     ->badge(),

//                 // ==========================================================
//                 // --- (LANGKAH 4) PERBAIKI LOGIKA 'VARIANCE' INI ---
//                 // ==========================================================
//                 Tables\Columns\TextColumn::make('variance')
//                     ->label('Variance')
//                     ->badge()
//                     ->numeric()
//                     ->getStateUsing(function (Model $record): string {
//                         // $record adalah StockCountItem
//                         if ($record->final_counted_stock === null) {
//                             return 'N/A';
//                         }

//                         $record->loadMissing('product.uoms');
//                         $product = $record->product;
//                         if (!$product) return 'N/A';

//                         // 1. Ambil Stok Sistem (Base UoM)
//                         $system_base = (float)$record->system_stock;

//                         // 2. Ambil Stok Fisik (Input)
//                         $counted_input = (float)$record->final_counted_stock;
//                         $uom_input = $record->final_counted_uom ?? $product->base_uom;

//                         // 3. Cari Conversion Rate
//                         $uomData = $product->uoms->where('uom_name', $uom_input)->first();
//                         $conversionRate = $uomData?->conversion_rate ?? 1.0;
//                         $conversionRate = ($conversionRate == 0) ? 1 : $conversionRate; // Safety check

//                         // 4. Konversi Hitungan Fisik ke Base UoM
//                         $counted_base = $counted_input * $conversionRate;

//                         // 5. Hitung Selisih
//                         $variance = $counted_base - $system_base;

//                         return $variance . ' ' . $product->base_uom;
//                     })
//                     ->color(function ($state) {
//                         if ($state === 'N/A') return 'gray';
//                         // Ambil angkanya saja (misal: "-10 PCS" -> -10)
//                         $varianceValue = (float) explode(' ', $state)[0];
//                         return $varianceValue == 0 ? 'success' : 'danger';
//                     }),
//             ])
//             ->headerActions([
//                 Tables\Actions\Action::make('changeDisplayUom')
//                     ->label('Display Qty In')
//                     ->icon('heroicon-o-cube')
//                     ->color('gray')
//                     ->form(function ($livewire) {
//                         return [
//                             Select::make('displayUomSelect')
//                                 ->label('Select Unit of Measure')
//                                 ->live()
//                                 ->options(function () use ($livewire): array {
//                                     $stockCount = $livewire->getOwnerRecord()->load('items.product.uoms');
//                                     $availableUoms = $stockCount->items
//                                         ->pluck('product.uoms')
//                                         ->flatten()
//                                         ->whereNotNull()
//                                         ->pluck('uom_name')
//                                         ->unique()
//                                         ->sort()
//                                         ->values()
//                                         ->all();
//                                     $baseUomName = $stockCount->items->first()?->product?->base_uom ?? 'base';
//                                     $options = ['base' => "Base UoM ({$baseUomName})"];
//                                     foreach ($availableUoms as $uom) {
//                                         if (strcasecmp($uom, $baseUomName) !== 0) {
//                                             $options[$uom] = $uom;
//                                         }
//                                     }
//                                     return $options;
//                                 })
//                                 ->default($livewire->displayUom)
//                                 ->afterStateUpdated(fn ($livewire, $state) => $livewire->displayUom = $state),
//                         ];
//                     })
//                     ->modalCloseButton(false)
//                     ->modalCancelAction(false)
//                     ->modalSubmitActionLabel('Set View'),
//             ])
//             ->actions([
//                 Tables\Actions\Action::make('inputTeamCount')
//                     ->label('Input Team Count')
//                     ->icon('heroicon-o-pencil-square')
//                     ->color('info')
//                     ->form([
//                         Grid::make(2)
//                             ->schema([
//                                 Placeholder::make('product_name')
//                                     ->content(fn(Model $record) => $record->product?->name . ' (' . $record->inventory?->batch . ')')
//                                     ->columnSpanFull(),
//                                 Placeholder::make('system_stock')
//                                     ->content(fn(Model $record) => $record->system_stock . ' ' . $record->product?->base_uom)
//                                     ->columnSpanFull(),

//                                 Checkbox::make('is_zero_count_team')
//                                     ->label('Mark as Zero Count')
//                                     ->live()
//                                     ->afterStateUpdated(function (Set $set, Model $record, $state) {
//                                         if ($state === true) {
//                                             $set('counted_quantity', 0);
//                                             $record->loadMissing('product');
//                                             $set('counted_uom', $record->product?->base_uom ?? 'pcs');
//                                         }
//                                     })
//                                     ->columnSpanFull(),

//                                 TextInput::make('counted_quantity')
//                                     ->label('My Counted Quantity')
//                                     ->numeric()
//                                     ->required()
//                                     ->autofocus()
//                                     ->disabled(fn (Get $get) => $get('is_zero_count_team') === true)
//                                     ->columnSpan(1),

//                                 Select::make('counted_uom')
//                                     ->label('UoM')
//                                     ->options(function (Model $record): array {
//                                         $record->loadMissing('product.uoms');
//                                         return $record->product?->uoms->pluck('uom_name', 'uom_name')->toArray() ?? [];
//                                     })
//                                     ->default(function (Model $record) {
//                                         $record->loadMissing('product');
//                                         return $record->product?->base_uom ?? 'pcs';
//                                     })
//                                     ->required(fn (Get $get): bool => $get('is_zero_count_team') !== true)
//                                     ->disabled(fn (Get $get) => $get('is_zero_count_team') === true)
//                                     ->columnSpan(1),

//                                 Hidden::make('team_name'),
//                             ])
//                     ])
//                     ->mountUsing(function(Form $form, Model $record, RelationManager $livewire) {
//                         $user = Auth::user();
//                         $ownerRecord = $livewire->getOwnerRecord();
//                         $teams = $ownerRecord->assigned_teams ?? [];
//                         $teamName = null;
//                         if (in_array($user->id, $teams['yellow'] ?? [])) $teamName = 'Kuning';
//                         elseif (in_array($user->id, $teams['green'] ?? [])) $teamName = 'Hijau';

//                         $record->loadMissing('entries');
//                         $entry = $record->entries->where('team_name', $teamName)->first();
//                         $isZero = $entry?->is_zero_count ?? false;

//                         $form->fill([
//                             'team_name' => $teamName,
//                             'counted_quantity' => $entry?->counted_quantity ?? null,
//                             'counted_uom' => $entry?->counted_uom ?? $record->product?->base_uom ?? 'pcs',
//                             'is_zero_count_team' => $isZero,
//                         ]);
//                     })
//                     ->action(function (Model $record, array $data) {
//                         try {
//                             $user = Auth::user();
//                             $teamName = $data['team_name'];
//                             if (!$teamName) {
//                                 throw new \Exception('You are not assigned to Team Kuning or Hijau.');
//                             }
//                             $record->entries()->updateOrCreate(
//                                 [
//                                     'stock_count_item_id' => $record->id,
//                                     'team_name' => $teamName,
//                                 ],
//                                 [
//                                     'user_id' => $user->id,
//                                     'counted_quantity' => (float)$data['counted_quantity'],
//                                     'counted_uom' => $data['counted_uom'],
//                                     'is_zero_count' => (bool)$data['is_zero_count_team'],
//                                 ]
//                             );
//                             Notification::make()->title("Count for Team {$teamName} saved!")->success()->send();
//                         } catch (\Exception $e) {
//                             Notification::make()->title('Failed to save count!')->body($e->getMessage())->danger()->send();
//                         }
//                     })
//                     ->visible(function (RelationManager $livewire) {
//                         // ... (logika visible Anda sudah benar)
//                         $user = Auth::user();
//                         if (!$user) return false;
//                         $ownerRecord = $livewire->getOwnerRecord();
//                         $teams = $ownerRecord->assigned_teams ?? [];
//                         $isTeamMember = in_array($user->id, $teams['yellow'] ?? []) || in_array($user->id, $teams['green'] ?? []);
//                         return $ownerRecord->status === 'in_progress' && $isTeamMember;
//                     }),

//                 Tables\Actions\EditAction::make('setFinalCount')
//                     ->label('Set Final Count')
//                     ->mountUsing(function (Form $form, Model $record) {
//                         $isZero = (bool)$record->is_zero_count;

//                         $form->fill(
//                             array_merge(
//                                 $record->toArray(),
//                                 ['is_zero_count_validator' => $isZero]
//                             )
//                         );
//                     })
//                     ->form(fn(Form $form) => $this->form($form)->getComponents())
//                     ->visible(function (RelationManager $livewire) {
//                         $user = Auth::user();
//                         if (!$user) return false;
//                         $ownerRecord = $livewire->getOwnerRecord();
//                         $validatorIds = $ownerRecord->assigned_teams['white'] ?? [];
//                         return $ownerRecord->status === 'pending_validation' && in_array($user->id, $validatorIds);
//                     }),
//             ])
//             ->bulkActions([
//                 Tables\Actions\BulkActionGroup::make([
//                     Tables\Actions\DeleteBulkAction::make(),
//                 ]),
//             ]);
//     }
// }
