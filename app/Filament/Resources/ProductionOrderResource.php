<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductionOrderResource\Pages;
use App\Filament\Resources\ProductionOrderResource\RelationManagers;
use App\Models\Plant;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
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

class ProductionOrderResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = ProductionOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
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
        return self::userHasPermission('view production orders');
    }
    public static function canCreate(): bool
    {
        return self::userHasPermission('create production orders');
    }

    // Filter berdasarkan Business ID (dan Plant jika perlu)
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);

        // Filter agar Staff/Manager hanya lihat PO di Plant mereka
        if (!self::userHasRole('Owner')) {
             $userPlantId = null;
             $user->loadMissing('locationable');
             if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
                 $userPlantId = $user->locationable->plant_id;
             }

             if ($userPlantId) {
                 $query->where('plant_id', $userPlantId);
             } else {
                 $query->whereRaw('0 = 1'); // Sembunyikan jika tidak terhubung ke Plant
             }
        }

        return $query;
    }


    public static function form(Form $form): Form
    {
         return $form
            ->schema([
                Forms\Components\Section::make('Production Order Details')
                    ->schema([
                        // 1. Pilih Plant Produksi
                        Forms\Components\Select::make('plant_id')
                            ->label('Production Plant')
                            ->options(function() {
                                $user = Auth::user();
                                $query = Plant::where('business_id', $user->business_id)
                                             ->whereIn('type', ['MANUFACTURING']); // Hanya Plant Manufaktur

                                // Filter ke Plant user jika bukan Owner
                                if (!self::userHasRole('Owner')) {
                                     $userPlantId = null;
                                     if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
                                         $userPlantId = $user->locationable->plant_id;
                                     }
                                     if ($userPlantId) {
                                         $query->where('id', $userPlantId);
                                     } else {
                                         $query->whereRaw('0 = 1'); // Blok jika tidak punya plant
                                     }
                                }
                                return $query->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required(),

                        // 2. Pilih Produk Jadi (Filter has('bom'))
                        Forms\Components\Select::make('finished_good_id')
                            ->label('Product to Produce')
                            ->relationship(
                                name: 'finishedGood',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) =>
                                    $query->where('business_id', Auth::user()->business_id)
                                          ->where('product_type', 'finished_good')
                                          // ===================================
                                          // --- PERBAIKAN: Gunakan has('bom') ---
                                          ->has('bom')
                                          // ===================================
                            )
                            ->searchable()->preload()->required()->live(),

                        // 3. Kuantitas
                        Forms\Components\TextInput::make('quantity_planned')
                            ->numeric()->required()->minValue(1)->default(1),

                        Forms\Components\Select::make('planned_uom')
                        ->label('Unit of Measure')
                        ->options(function (Get $get): array {
                            $product = Product::find($get('finished_good_id'));
                            if (!$product) return [];

                            // Tambahkan ->toArray() di akhir
                            return $product->uoms()->pluck('uom_name', 'uom_name')->toArray();
                        })
                        ->required()
                        ->columnSpan(1),

                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('production_order_number')->searchable(),
                Tables\Columns\TextColumn::make('plant.name')->label('Plant')->sortable(), // Tampilkan Plant
                Tables\Columns\TextColumn::make('finishedGood.name'),
                Tables\Columns\TextColumn::make('quantity_planned'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->colors([
                        'gray' => 'draft',
                        'danger' => 'insufficient_materials',
                        'warning' => 'pending_picking',
                        'info' => 'ready_to_produce',
                        'primary' => 'in_progress',
                        'success' => 'completed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plant_id')
                    ->label('Plant')
                    ->options(fn() => Plant::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(), // Tambahkan View
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
            RelationManagers\RequiredComponentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductionOrders::route('/'),
            'create' => Pages\CreateProductionOrder::route('/create'),
            'edit' => Pages\EditProductionOrder::route('/{record}/edit'),
            'view' => Pages\ViewProductionOrder::route('/{record}'),
        ];
    }
}
