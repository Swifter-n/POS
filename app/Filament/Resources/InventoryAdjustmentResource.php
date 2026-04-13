<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryAdjustmentResource\Pages;
use App\Filament\Resources\InventoryAdjustmentResource\RelationManagers;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = InventoryAdjustment::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-in';
    protected static ?string $navigationGroup = 'Inventory Management';

    private static function userHasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if (self::userHasRole('Owner')) return true;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }

    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }

    public static function canViewAny(): bool
    {
        return self::userHasPermission('view inventory adjustments');
    }
    public static function canCreate(): bool
    {
        return self::userHasPermission('create inventory adjustments');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }
        $query = parent::getEloquentQuery()->with([
            'plant',
            'warehouse',
            'createdBy:id,name'
        ])->where('business_id', $user->business_id);
        if (self::userHasRole('Owner')) {
            return $query;
        }
        $userPlantId = null;
        $user->loadMissing('locationable');
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        } elseif ($user->locationable_type === Outlet::class && $user->locationable?->supplying_plant_id) {
            $userPlantId = $user->locationable->supplying_plant_id;
        }
        if ($userPlantId) {
            $query->where('plant_id', $userPlantId);
        } else {
            $query->whereRaw('0 = 1');
        }
        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Adjustment Details')
                    ->schema([
                        Forms\Components\TextInput::make('adjustment_number')
                            ->default('ADJ-' . date('Ym') . '-' . random_int(1000, 9999))
                            ->disabled()
                            ->dehydrated() // Pastikan tersimpan
                            ->required(),

                        // 1. Pilih Plant Dulu
                        Forms\Components\Select::make('plant_id')
                            ->label('Plant / DC')
                            ->options(fn() => Plant::where('business_id', Auth::user()->business_id)
                                        ->where('status', true)
                                        ->pluck('name', 'id'))
                            ->searchable()->preload()->live()->required(),

                        // 2. Pilih Warehouse (Tergantung Plant)
                        Forms\Components\Select::make('warehouse_id')
                            ->label('Warehouse')
                            ->options(fn(Get $get) => Warehouse::where('plant_id', $get('plant_id'))
                                        ->where('status', true)->pluck('name', 'id'))
                            ->searchable()->preload()->live()->required(),

                        // Hapus 'location_id' dari header, pindah ke item

                        Forms\Components\Select::make('type')
                            ->label('Adjustment Type')
                            ->options([
                                'ADJUST_DAMAGE' => 'Stock Damage (Write-Off)',
                                'ADJUST_FOUND' => 'Stock Found (Gain)',
                                'ADJUST_MANUAL' => 'Manual Correction',
                                // 'STOCK_COUNT' dibuat otomatis
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Items to Adjust')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('location_id')
                                    ->label('Filter by Location (Optional)')
                                    ->options(function (Get $get) {
                                        $warehouseId = $get('../../warehouse_id');
                                        if (!$warehouseId) return [];
                                        return Location::where('locatable_type', Warehouse::class)
                                            ->where('locatable_id', $warehouseId)
                                            ->where('status', true)
                                            ->pluck('name', 'id');
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->reactive()
                                    ->helperText('Select a specific location to filter the inventory list below.')
                                    ->columnSpanFull(),

                                // 2. Pilih Item Batch (Inventory)
                                Forms\Components\Select::make('inventory_id')
                                    ->label('Select Item (Product - Batch - Location)')
                                    ->options(function (Get $get) {
                                        $warehouseId = $get('../../warehouse_id');
                                        $locationId = $get('location_id');

                                        if (!$warehouseId) return [];
                                        $baseQuery = Inventory::query();

                                        if ($locationId) {
                                            $baseQuery->where('location_id', $locationId);
                                        } else {
                                            $locationIds = Location::where('locatable_type', Warehouse::class)
                                                ->where('locatable_id', $warehouseId)
                                                ->where('status', true)
                                                ->pluck('id');
                                            if ($locationIds->isEmpty()) return [];
                                            $baseQuery->whereIn('location_id', $locationIds);
                                        }
                                        return $baseQuery
                                            ->with('product', 'location')
                                            ->get()
                                            ->mapWithKeys(fn($inv) => [
                                                $inv->id => ($inv->product?->name ?? 'N/A') .
                                                            " (Batch: {$inv->batch}) @ " .
                                                            ($inv->location?->name ?? 'N/A')
                                            ]);
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->required()
                                    ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                         // Ambil inventory
                                         $inventory = Inventory::find($state);
                                         $stock = $inventory?->avail_stock ?? 0;
                                         $set('quantity_before', $stock);

                                         // [PERBAIKAN] Set default input qty = 0
                                         $set('input_qty', 0);
                                         // [PERBAIKAN] Set default UoM ke Base UoM
                                         $baseUom = $inventory?->product?->base_uom ?? 'PCS';
                                         $set('input_uom', $baseUom);

                                         // Panggil kalkulasi
                                         self::calculateChange($get, $set);
                                    })
                                    ->columnSpan(2),

                                Forms\Components\TextInput::make('quantity_before')
                                    ->label('System Stock (Base UoM)')
                                    ->numeric()->disabled()->dehydrated(),

                                // ==========================================================
                                // --- [PERBAIKAN] Ganti 'quantity_after' ---
                                // ==========================================================
                                Forms\Components\TextInput::make('input_qty')
                                    ->label('New Physical Qty')
                                    ->numeric()->required()
                                    ->reactive()
                                    ->live(onBlur: true)
                                    ->default(0)
                                    ->afterStateUpdated(fn(Get $get, Set $set) => self::calculateChange($get, $set)),

                                Forms\Components\Select::make('input_uom')
                                    ->label('UoM')
                                    ->options(function (Get $get): array {
                                        $inventory = Inventory::find($get('inventory_id'));
                                        $product = $inventory?->product;
                                        if (!$product) return [];
                                        return $product->uoms()->pluck('uom_name', 'uom_name')->toArray();
                                    })
                                    ->required()
                                    ->reactive()
                                    ->default(function (Get $get): string {
                                        $inventory = Inventory::find($get('inventory_id'));
                                        return $inventory?->product?->base_uom ?? 'PCS';
                                    })
                                    ->afterStateUpdated(fn(Get $get, Set $set) => self::calculateChange($get, $set)),
                                // ==========================================================

                                // [PERBAIKAN] Tampilkan perubahan (read-only)
                                Forms\Components\Placeholder::make('quantity_change_display')
                                    ->label('Change (Base UoM)')
                                    ->content(function (Get $get): string {
                                        $change = (float) $get('quantity_change');
                                        if ($change > 0) return "+{$change}";
                                        return (string) $change;
                                    })
                                    ->extraAttributes(function (Get $get): array {
                                        $change = (float) $get('quantity_change');
                                        $color = $change > 0 ? 'success' : ($change < 0 ? 'danger' : 'gray');
                                        return ['class' => "text-{$color}-500 font-bold"];
                                    }),

                                // [BARU] Field-field ini akan diisi oleh kalkulasi
                                Hidden::make('quantity_after'),
                                Hidden::make('quantity_change'),
                            ])
                            ->columns(6) // Sesuaikan jumlah kolom
                            ->addActionLabel('Add Item'),
                    ]),
            ]);
    }

    /**
     * [BARU] Helper terpusat untuk kalkulasi UoM
     */
    public static function calculateChange(Get $get, Set $set): void
    {
        $inventory = Inventory::find($get('inventory_id'));
        if (!$inventory) return;

        $inventory->loadMissing('product.uoms');

        $quantityBefore = (float) $get('quantity_before');
        $inputQty = (float) $get('input_qty');
        $inputUomName = $get('input_uom');

        // 1. Cari conversion rate
        $uomData = $inventory->product?->uoms->where('uom_name', $inputUomName)->first();
        $conversionRate = $uomData?->conversion_rate ?? 1;

        // 2. Hitung nilai baru dalam Base UoM
        $quantityAfter_BaseUoM = $inputQty * $conversionRate;
        $quantityChange_BaseUoM = $quantityAfter_BaseUoM - $quantityBefore;

        // 3. Set nilainya (termasuk yang hidden)
        $set('quantity_after', (int) round($quantityAfter_BaseUoM));
        $set('quantity_change', (int) round($quantityChange_BaseUoM));

        // (Ini hanya untuk memicu update 'quantity_change_display' Placeholder)
        $set('quantity_change_display', (int) round($quantityChange_BaseUoM));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('adjustment_number')->searchable(),
                // ==========================================================
                // --- KOLOM BARU (PLANT & WAREHOUSE) ---
                // ==========================================================
                Tables\Columns\TextColumn::make('plant.name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('warehouse.name')->searchable()->sortable(),
                // ==========================================================
                Tables\Columns\TextColumn::make('type')->badge()->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'draft',
                    'success' => 'posted',
                ]),
                Tables\Columns\TextColumn::make('createdBy.name'),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plant_id')
                    ->label('Plant')
                    ->relationship('plant', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('Warehouse')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryAdjustments::route('/'),
            'create' => Pages\CreateInventoryAdjustment::route('/create'),
            'edit' => Pages\EditInventoryAdjustment::route('/{record}/edit'),
        ];
    }
}
