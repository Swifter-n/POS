<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Filament\Resources\InventoryResource\RelationManagers;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as ComponentsSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class InventoryResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = Inventory::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';
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

       // ==========================================================
    // --- HANYA BISA DILIHAT (READ-ONLY) ---
    // ==========================================================
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; } // Nonaktifkan Edit
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function canViewAny(): bool
    {
        return self::userHasPermission('view inventory'); // Ganti permission
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery()->with([
            'product:id,sku,name,base_uom',
            'location' => function ($query) {
                $query->with(['zone:id,code,name', 'locatable'])
                      ->with(['locatable.plant', 'locatable.supplyingPlant']);
            },
        ]);

        if (!$user || !$user->business_id) return $query->whereRaw('0 = 1');

        $query->where('business_id', $user->business_id);

        if (!self::userHasRole('Owner')) {
            $userPlantId = null;
            $userOutletId = null;
            $user->loadMissing('locationable');

            if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
                $userPlantId = $user->locationable->plant_id;
            } elseif ($user->locationable_type === Outlet::class) {
                $userOutletId = $user->locationable_id;
                $userPlantId = $user->locationable?->supplying_plant_id;
            }

            $query->where(function (Builder $q) use ($userPlantId, $userOutletId) {
                $hasFilter = false;
                if ($userPlantId) {
                    $hasFilter = true;
                    // Bungkus logika OR di dalam where terpisah
                    $q->where(function ($subQ) use ($userPlantId) {
                     $subQ->whereHas('location', function (Builder $locQuery) use ($userPlantId) {
                            $locQuery->where(function (Builder $nestedQ) use ($userPlantId) {
                                $nestedQ->where('locatable_type', Warehouse::class)
                                    ->whereHasMorph('locatable', [Warehouse::class], fn($wh) => $wh->where('plant_id', $userPlantId));
                            })->orWhere(function (Builder $nestedQ) use ($userPlantId) {
                                $nestedQ->where('locatable_type', Outlet::class)
                                    ->whereHasMorph('locatable', [Outlet::class], fn($ot) => $ot->where('supplying_plant_id', $userPlantId));
                            });
                        });
                    });
                }

                if ($userOutletId) {
                    $hasFilter = true;
                    $q->orWhereHas('location', function (Builder $locQuery) use ($userOutletId) {
                         $locQuery->where('locatable_type', Outlet::class)
                                  ->where('locatable_id', $userOutletId);
                    });
                }

                if (!$hasFilter) {
                    $q->whereRaw('0 = 1');
                }
            });
        }

        // --- FIX UTAMA: Gunakan whereRaw untuk memaksa filter stok ---
        $query->whereRaw('avail_stock > 0');

        return $query;
    }

    // ==========================================================
    // --- GANTI form() DENGAN infolist() ---
    // ==========================================================
     public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ==========================================================
                // --- PERBAIKAN: Gunakan 'InfolistSection' ---
                // ==========================================================
                ComponentsSection::make('Inventory Details')
                    ->schema([
                        TextEntry::make('product.name'),
                        TextEntry::make('batch'),
                        TextEntry::make('sled')->date(),
                        TextEntry::make('avail_stock')
                            ->label('Available Stock')
                            ->badge()
                            ->formatStateUsing(fn(Model $record) => $record->avail_stock . ' ' . $record->product?->base_uom),
                    ])->columns(2),
                ComponentsSection::make('Location Details')
                    ->schema([
                        // Tampilkan Plant
                        TextEntry::make('location.locatable.plant.name')->label('Plant'),
                        TextEntry::make('location.locatable.name')->label('Warehouse/Outlet'),
                        TextEntry::make('location.zone.name')->label('Zone')->badge(),
                        TextEntry::make('location.name')->label('Location'),
                        TextEntry::make('location.ownership_type')->label('Ownership')->badge(),
                        TextEntry::make('location.supplier.name')->label('Consignment Supplier'),
                    ])->columns(2),
            ]);
    }
    // ==========================================================


    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
            // Pastikan stok > 0 (Strict)
            $query->whereRaw('avail_stock > 0');

            // Urutkan biar yang stoknya ada muncul di atas (Opsional)
            return $query->orderBy('avail_stock', 'desc');
            })
            ->defaultSort('sled', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('batch')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('sled')->label('SLED')->date()->sortable(),
                Tables\Columns\TextColumn::make('avail_stock')->label('Stock')
                    ->numeric()->sortable()
                    // ==========================================================
                    // --- PERBAIKAN: Logika UoM (Gunakan session) ---
                    // ==========================================================
                    ->formatStateUsing(function (Model $record) {
                        $state = $record->avail_stock; // Ambil nilai
                        $record->loadMissing('product.uoms'); // Pastikan UoMs dimuat

                        // Ambil UoM yang dipilih dari filter
                        $displayUom = session('inventory_display_uom', $record->product?->base_uom ?? 'PCS');
                        $baseUomName = $record->product?->base_uom ?? 'PCS';

                        if ($displayUom === $baseUomName) {
                            return Number::format($state, 0) . " {$baseUomName}";
                        }

                        // Cari rate konversi
                        $uom = $record->product?->uoms->where('uom_name', $displayUom)->first();
                        if ($uom && $uom->conversion_rate > 1) {
                            $conversionRate = $uom->conversion_rate;
                            $convertedQty = floor($state / $conversionRate);
                            $remainingPcs = fmod($state, $conversionRate);

                            if ($remainingPcs > 0) {
                                return "{$convertedQty} {$displayUom} + {$remainingPcs} {$baseUomName}";
                            }
                            return "{$convertedQty} {$displayUom}";
                        }
                        return Number::format($state, 0) . " {$baseUomName}"; // Fallback
                    }),
                    // ==========================================================


                // --- Kolom Konsep Baru ---
                Tables\Columns\TextColumn::make('location.locatable.plant.name') // Plant
                    ->label('Plant')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('location.locatable.name') // Warehouse/Outlet
                    ->label('Warehouse/Outlet')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                // ==========================================================
                // --- PERBAIKAN: Gunakan 'zone.code' (bukan 'location.code') ---
                // ==========================================================
                Tables\Columns\TextColumn::make('location.zone.code')
                    ->label('Zone')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'FG', 'FAST', 'LINE-A' => 'success',
                        'RM', 'COLD' => 'info',
                        'STG', 'RCV', 'MAIN' => 'gray',
                        'QI' => 'warning',
                        'DMG', 'RET' => 'danger',
                        'Z-CON' => 'purple',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                // ==========================================================

                Tables\Columns\TextColumn::make('location.name') // Lokasi
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location.ownership_type') // Kepemilikan
                    ->label('Ownership')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'owned' ? 'success' : 'warning')
                    ->sortable(),
            ])
            ->filters([
                // ==========================================================
                // --- FILTER CANGGIH (DIPERBARUI) ---
                // ==========================================================
                Tables\Filters\SelectFilter::make('display_uom')
                    ->label('Display Stock In')
                    ->options(
                        // Ambil semua UoM unik (dari script lama Anda)
                        ProductUom::distinct()->pluck('uom_name', 'uom_name')
                    )
                    ->query(function (Builder $query, array $data) {
                        // Simpan pilihan user di session
                        if (!empty($data['value'])) {
                            session(['inventory_display_uom' => $data['value']]);
                        } else {
                             session(['inventory_display_uom' => 'base']); // Default ke base
                        }
                    })
                    ->default(session('inventory_display_uom', 'base')), // Ambil dari session

                Tables\Filters\SelectFilter::make('plant')
                    ->label('Plant')
                    ->options(fn() => Plant::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                    // Query (sudah benar)
                    ->query(fn (Builder $query, array $data) =>
                        $query->when($data['value'], fn($q) =>
                            $q->whereHas('location', fn($locQ) =>
                                $locQ->where(function (Builder $subQ) use ($data) {
                                    $subQ->where(fn($whQ) => $whQ->where('locatable_type', Warehouse::class)->whereHasMorph('locatable', [Warehouse::class], fn($wh) => $wh->where('plant_id', $data['value'])))
                                         ->orWhere(fn($otQ) => $otQ->where('locatable_type', Outlet::class)->whereHasMorph('locatable', [Outlet::class], fn($ot) => $ot->where('supplying_plant_id', $data['value'])));
                                })
                            )
                        )
                    )
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('warehouse_outlet')
                    ->label('Warehouse/Outlet')
                    // Relasi polimorfik tidak bisa difilter dgn ->relationship()
                    // Opsi ini mengambil SEMUA, idealnya difilter by Plant terpilih
                    ->options(function () {
                        $warehouses = Warehouse::where('business_id', Auth::user()->business_id)
                                      ->pluck('name', 'id')
                                      ->mapWithKeys(fn($name, $id) => [Warehouse::class . '_' . $id => "WH: $name"]);
                        $outlets = Outlet::where('business_id', Auth::user()->business_id)
                                      ->pluck('name', 'id')
                                      ->mapWithKeys(fn($name, $id) => [Outlet::class . '_' . $id => "Outlet: $name"]);
                        return $warehouses->union($outlets);
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) return $query;
                        [$type, $id] = explode('_', $data['value']);
                        return $query->whereHas('location', function (Builder $q) use ($type, $id) {
                            $q->where('locatable_type', $type)->where('locatable_id', $id);
                        });
                    })
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('zone_id')
                    ->label('Zone')
                    ->relationship('location.zone', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                //Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Tidak ada bulk actions
            ]);
    }

    // Tidak ada relations
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'), // (Walaupun canCreate() false, ini tetap diperlukan)
            'edit' => Pages\EditInventory::route('/{record}/edit'), // (Walaupun canEdit() false, ini tetap diperlukan)
            'view' => Pages\ListInventories::route('/{record}'), // <-- TAMBAHKAN HALAMAN VIEW
        ];
    }
}
