<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseReturnResource\Pages;
use App\Filament\Resources\PurchaseReturnResource\RelationManagers;
use App\Models\Inventory;
use App\Models\Plant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
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

class PurchaseReturnResource extends Resource
{
    protected static ?string $model = PurchaseReturn::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-down';
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


      public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) return parent::getEloquentQuery()->whereRaw('0 = 1');

        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);

        if (self::userHasRole('Owner')) {
            return $query; // Owner bisa lihat semua
        }

        $userPlantId = null;
        $user->loadMissing('locationable');
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        } // Tambahkan logika Outlet jika Outlet bisa melakukan return

        if ($userPlantId) {
            // Tampilkan return dari Plant user
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
                Forms\Components\Section::make('Return Details')
                    ->schema([
                        // 1. Pilih Plant Dulu
                        Forms\Components\Select::make('plant_id')
                            ->label('Plant')
                            ->options(fn() => Plant::where('business_id', Auth::user()->business_id)
                                        ->where('status', true)->pluck('name', 'id'))
                            ->searchable()->preload()->live()->required(),

                        // 2. Pilih Warehouse (Tergantung Plant)
                        Forms\Components\Select::make('warehouse_id')
                            ->label('Warehouse (Origin)')
                            ->options(fn(Get $get) => Warehouse::where('plant_id', $get('plant_id'))
                                        ->where('status', true)->pluck('name', 'id'))
                            ->searchable()->preload()->live()->required(),

                        // 3. Pilih Supplier
                        Forms\Components\Select::make('supplier_id')
                            ->relationship(
                                name: 'supplier',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn(Builder $query) => $query
                                    ->where('business_id', Auth::user()->business_id)
                                    ->where('type', 'Supplier')
                                )
                            ->searchable()->preload()->required()->live(),

                        // 4. (Opsional) Referensi PO
                        Forms\Components\Select::make('purchase_order_id')
                            ->label('Reference PO (Optional)')
                            ->options(function (Get $get) {
                                $supplierId = $get('supplier_id');
                                if (!$supplierId) return [];

                                // --- PERBAIKAN: Ganti 'supplier_id' menjadi 'vendor_id' ---
                                return PurchaseOrder::where('vendor_id', $supplierId)
                                            ->orderBy('order_date', 'desc')
                                            ->limit(50)
                                            ->pluck('po_number', 'id');
                            })
                            ->preload()
                            ->searchable(),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ])->columns(2),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('return_number')->searchable(),
                Tables\Columns\TextColumn::make('supplier.name')->searchable(),
                Tables\Columns\TextColumn::make('plant.name')->label('Plant'),
                Tables\Columns\TextColumn::make('warehouse.name')->label('From Warehouse'),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'draft',
                    'warning' => 'approved',
                    'info' => 'shipped',
                    'success' => 'completed'
                ]),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plant_id')
                    ->relationship('plant', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('warehouse_id')
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
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseReturns::route('/'),
            'create' => Pages\CreatePurchaseReturn::route('/create'),
            'edit' => Pages\EditPurchaseReturn::route('/{record}/edit'),
        ];
    }
}
