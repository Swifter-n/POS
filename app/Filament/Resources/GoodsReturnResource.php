<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GoodsReturnResource\Pages;
use App\Filament\Resources\GoodsReturnResource\RelationManagers;
use App\Models\GoodsReturn;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Plant;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
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

class GoodsReturnResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = GoodsReturn::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';
    protected static ?string $navigationGroup = 'Inventory Management';

    // (Helper userHasPermission & userHasRole tetap sama)
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
        return self::userHasPermission('view goods returns');
    }
    public static function canCreate(): bool
    {
        return self::userHasPermission('create goods returns'); // (Ini untuk Retur Manual)
    }

    // ==========================================================
    // --- getEloquentQuery (Filter by Plant & Status) ---
    // ==========================================================
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) return parent::getEloquentQuery()->whereRaw('0 = 1');

        $query = parent::getEloquentQuery()
                    ->where('business_id', $user->business_id)
                    // [PERBAIKAN] Tampilkan SEMUA retur, bukan hanya dari plant
                    ->with(['salesOrder:id,so_number', 'customer:id,name']);

        if (self::userHasRole('Owner')) {
            return $query; // Owner bisa lihat semua
        }

        // [LOGIKA BARU] Filter berdasarkan Plant (jika user terikat)
        $userPlantId = null;
        $user->loadMissing('locationable');
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        }

        if ($userPlantId) {
            // Tampilkan return dari SO yang plant-nya sama ATAU
            // Tampilkan return yang plant_id-nya sama (jika diisi manual)
            $query->where(function (Builder $q) use ($userPlantId) {
                $q->where('plant_id', $userPlantId)
                  ->orWhereHas('salesOrder', fn(Builder $so) =>
                        $so->whereHas('customer', fn(Builder $cust) => $cust->where('supplying_plant_id', $userPlantId))
                  );
            });
        } else {
            // Jika user tidak punya plant (misal: Admin non-gudang),
            // Tampilkan semua (sudah difilter by business_id)
        }

        return $query;
    }

    // ==========================================================
    // --- form() (Untuk Retur Manual) ---
    // ==========================================================
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Goods Return (Manual Entry)')
                    ->schema([
                        Forms\Components\TextInput::make('return_number')
                            ->default('GRT-' . date('Ym') . '-' . random_int(1000, 9999))
                            ->disabled()
                            ->dehydrated()
                            ->required(),

                        // [BARU] Pilih SO (Opsional, untuk referensi)
                        Forms\Components\Select::make('sales_order_id')
                            ->label('Reference Sales Order')
                            ->relationship('salesOrder', 'so_number')
                            ->searchable()->preload()->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                $so = SalesOrder::find($state);
                                if ($so) $set('customer_id', $so->customer_id);
                            }),

                        // [BARU] Pilih Customer (Opsional, untuk referensi)
                        Forms\Components\Select::make('customer_id')
                            ->label('Customer')
                            ->relationship('customer', 'name')
                            ->searchable()->preload(),

                        Forms\Components\DatePicker::make('return_date')
                            ->label('Return Date')
                            ->default(now())
                            ->required(),

                        Forms\Components\Select::make('plant_id')
                            ->label('Return to Plant')
                            ->options(fn() => Plant::where('business_id', Auth::user()->business_id)
                                        ->where('status', true)->pluck('name', 'id'))
                            ->searchable()->preload()->required()
                            ->helperText('Pilih plant yang akan menerima barang retur ini.'),

                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ])->columns(2),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('return_number')->searchable(),
                // [BARU] Tampilkan referensi SO
                Tables\Columns\TextColumn::make('salesOrder.so_number')
                    ->label('From SO')
                    ->searchable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('plant.name')->label('Return Plant'),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'draft', // Dibuat manual, menunggu item
                    'warning' => 'pending', // Dibuat POD, menunggu diterima gudang
                    'info' => 'receiving', // Sedang diterima gudang
                    'success' => 'received', // Selesai diterima
                    'danger' => 'cancelled',
                ]),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plant_id')
                    ->relationship('plant', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending' => 'Pending Receipt',
                        'receiving' => 'Receiving',
                        'received' => 'Received',
                        'cancelled' => 'Cancelled',
                    ])
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // ...
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // [BARU] Ganti Relation Manager ini
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGoodsReturns::route('/'),
            'create' => Pages\CreateGoodsReturn::route('/create'),
            'edit' => Pages\EditGoodsReturn::route('/{record}/edit'),
        ];
    }
}
