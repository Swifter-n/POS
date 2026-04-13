<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockCountResource\Pages;
use App\Filament\Resources\StockCountResource\RelationManagers;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\StockCount;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockCountResource extends Resource
{
    protected static ?string $model = StockCount::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Inventory Management';

    /**
     * Helper function untuk memeriksa permission via query langsung (paling andal).
     */
    private static function userHasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if (self::userHasRole('Owner')) return true;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }

    /**
     * Helper function untuk memeriksa role via query langsung.
     */
    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }

public static function canViewAny(): bool
    {
        return self::userHasPermission('view stock counts');
    }

    public static function canCreate(): bool
    {
        return self::userHasPermission('create stock counts');
    }

     public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);

        if (self::userHasRole('Owner')) {
            return $query; // Owner bisa lihat semua
        }

        // Filter berdasarkan Plant/Outlet user
        $userPlantId = null;
        $userOutletId = null;
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        } elseif ($user->locationable_type === Outlet::class && $user->locationable_id) {
            $userOutletId = $user->locationable_id;
        }

        // Tampilkan Stock Count yang 'countable'-nya (Warehouse/Outlet)
        // terhubung ke Plant atau Outlet user
        $query->where(function (Builder $q) use ($userPlantId, $userOutletId) {
            if ($userPlantId) {
                // Tampilkan SC Warehouse di Plant user
                $q->whereHasMorph(
                    'countable',
                    [Warehouse::class],
                    fn (Builder $whQuery) => $whQuery->where('plant_id', $userPlantId)
                );
            }
            if ($userOutletId) {
                 // Tampilkan SC Outlet di Outlet user
                $q->orWhereHasMorph(
                    'countable',
                    [Outlet::class],
                    fn (Builder $otQuery) => $otQuery->where('id', $userOutletId)
                );
            }
            // Jika user tidak terhubung ke Plant/Outlet, jangan tampilkan apa-apa
            if (!$userPlantId && !$userOutletId) {
                 $q->whereRaw('0 = 1');
            }
        });

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
        ->schema([
            Forms\Components\Section::make('Stock Count Details')
                ->schema([
                    // ==========================================================
                    // --- LANGKAH 1: PILIH TIPE LOKASI (BARU) ---
                    // ==========================================================
                    Forms\Components\Select::make('countable_type')
                        ->label('Location Type')
                        ->options([
                            Warehouse::class => 'Warehouse (at Plant/DC)',
                            Outlet::class => 'Outlet (Store)',
                        ])
                        ->required()
                        ->live() // 'live()' sangat penting
                        ->columnSpanFull(),

                    // ==========================================================
                    // --- LANGKAH 2: PILIH PLANT (HANYA JIKA TIPE WAREHOUSE) ---
                    // ==========================================================
                    Forms\Components\Select::make('plant_id')
                        ->label('Plant / DC')
                        ->options(fn() => Plant::where('business_id', Auth::user()->business_id)
                                    ->where('status', true)
                                    ->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required()
                        // Tampilkan HANYA jika Tipe Lokasi adalah Warehouse
                        ->visible(fn (Get $get) => $get('countable_type') === Warehouse::class)
                        ->dehydrated(true), // Pastikan ini tersimpan

                    // ==========================================================
                    // --- LANGKAH 3: PILIH NAMA LOKASI (DINAMIS) ---
                    // ==========================================================
                    Forms\Components\Select::make('countable_id')
                        ->label('Location Name')
                        ->options(function (Get $get): array {
                            $type = $get('countable_type');
                            if (!$type) return []; // Kosong jika tipe belum dipilih

                            // Tentukan model (Warehouse atau Outlet)
                            $model = new $type;
                            $query = $model::query()->where('business_id', Auth::user()->business_id);

                            // Jika Tipe adalah Warehouse...
                            if ($type === Warehouse::class) {
                                $plantId = $get('plant_id');
                                if (!$plantId) return []; // ...tunggu Plant dipilih
                                $query->where('plant_id', $plantId);
                            }
                            // Jika Tipe adalah Outlet, tidak perlu filter Plant
                            // (Query sudah memfilter berdasarkan business_id)

                            return $query->where('status', true)->pluck('name', 'id')->toArray();
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),

                    // ==========================================================
                    // --- LANGKAH 4: PILIH ZONA (OPSIONAL) ---
                    // (Logika ini sudah benar, tidak perlu diubah)
                    // ==========================================================
                    Forms\Components\Select::make('zone_id')
                        ->label('Specific Zone (Optional)')
                        ->options(function (Get $get): array {
                             $countableId = $get('countable_id');
                             $countableType = $get('countable_type');
                             if (!$countableId || !$countableType) return [];

                             return Location::where('locatable_type', $countableType)
                                     ->where('locatable_id', $countableId)
                                     ->where('status', true)
                                     ->whereNotNull('zone_id')
                                     ->join('zones', 'locations.zone_id', '=', 'zones.id')
                                     ->distinct()
                                     ->pluck('zones.name', 'zones.id')
                                     ->toArray();
                        })
                        ->searchable()
                        ->preload()
                        ->helperText('Leave blank to count ALL zones in this location.')
                        ->relationship('zone', 'name')
                        ->nullable(),

                    Forms\Components\Textarea::make('notes')
                        ->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('plant.name')->label('Plant')
                    ->searchable()
                    ->sortable()
                    ->default('N/A'), // Jika tidak ada plant_id
                Tables\Columns\TextColumn::make('countable.name')->label('Warehouse/Outlet'),
                Tables\Columns\TextColumn::make('zone.name')->label('Zone') // Tampilkan Zona
                    ->badge()
                    ->default('All Zones'),
                // ==========================================================
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'draft',
                    'info' => 'in_progress',
                    'warning' => 'pending_approval',
                    'success' => 'posted',
                ]),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Created By'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plant_id')
                    ->label('Plant')
                    ->relationship('plant', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('zone_id')
                    ->label('Zone')
                    ->relationship('zone', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockCounts::route('/'),
            'create' => Pages\CreateStockCount::route('/create'),
            'edit' => Pages\EditStockCount::route('/{record}/edit'),
        ];
    }
}
