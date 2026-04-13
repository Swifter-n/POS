<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryMoveResource\Pages;
use App\Filament\Resources\InventoryMoveResource\RelationManagers;
use App\Models\InventoryMove;
use App\Models\Outlet;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryMoveResource extends Resource
{
    protected static ?string $model = InventoryMove::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = 'Inventory Management';
    protected static ?string $navigationLabel = 'Ad-Hoc Stock Move';


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
        return self::userHasPermission('view adhoc moves'); // Ganti permission
    }
    public static function canCreate(): bool
    {
         return self::userHasPermission('execute adhoc move'); // Ganti permission
    }
    // Pergerakan yang sudah terjadi tidak bisa diedit
    public static function canEdit(Model $record): bool { return false; }
    // ==========================================================


    // =Form() didefinisikan di CreateInventoryMove.php

    // ==========================================================
    // --- getEloquentQuery (Sesuai Permintaan Anda) ---
    // ==========================================================
    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) return parent::getEloquentQuery()->whereRaw('0 = 1');

        $query = parent::getEloquentQuery()
                    ->where('business_id', $user->business_id)
                    ->with(['product', 'sourceLocation', 'destinationLocation', 'movedBy']); // Eager load

        // 1. Owner bisa lihat semua
        if (self::userHasRole('Owner')) {
            return $query;
        }

        // 2. Filter untuk Manager (Asumsi permission 'manage adhoc moves')
        //    dan Staff (Asumsi permission 'execute adhoc move')

        $userPlantId = null;
        $user->loadMissing('locationable');
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        } elseif ($user->locationable_type === Outlet::class && $user->locationable?->supplying_plant_id) {
            $userPlantId = $user->locationable->supplying_plant_id;
        }

        $query->where(function (Builder $q) use ($user, $userPlantId) {
            $canManage = self::userHasPermission('manage adhoc moves');
            $canExecute = self::userHasPermission('execute adhoc move');

            // Jika Manager, tampilkan semua di Plant-nya
            if ($canManage && $userPlantId) {
                 $q->where('plant_id', $userPlantId);
            }

            // Jika Staff, tampilkan HANYA miliknya
            if ($canExecute) {
                // 'orWhere' aman karena Manager juga pasti punya permission 'execute'
                 $q->orWhere('moved_by_user_id', $user->id);
            }

            // Jika role tidak jelas (bukan owner, manager, atau staff), sembunyikan
            if (!$canManage && !$canExecute) {
                 $q->whereRaw('0 = 1');
            }
        });

        return $query;
    }



    // public static function form(Form $form): Form
    // {
    //     return $form
    //         ->schema([
    //             //
    //         ]);
    // }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('move_number')->searchable(),
                Tables\Columns\TextColumn::make('product.name')->searchable(),
                Tables\Columns\TextColumn::make('inventory.batch')->label('Batch')->searchable(),
                Tables\Columns\TextColumn::make('input_quantity')
                    ->label('Qty Moved')
                    ->numeric()
                    ->suffix(fn (InventoryMove $record): string => " {$record->input_uom}")
                    ->toggleable(),

                Tables\Columns\TextColumn::make('quantity_base')
                    ->label('Qty (Base UoM)')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sourceLocation.name')->label('From Location'),
                Tables\Columns\TextColumn::make('destinationLocation.name')->label('To Location'),
                Tables\Columns\TextColumn::make('reason')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('movedBy.name')->label('Moved By')->sortable(),
                Tables\Columns\TextColumn::make('moved_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plant_id')
                    ->relationship('plant', 'name')
                    ->searchable()->preload()
                    ->visible(fn() => self::userHasRole('Owner')), // Hanya Owner yg bisa filter Plant
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name')
                    ->searchable()->preload(),
                Tables\Filters\SelectFilter::make('moved_by_user_id')
                    ->label('Moved By')
                    ->relationship('movedBy', 'name')
                    ->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Tidak ada bulk actions
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
            'index' => Pages\ListInventoryMoves::route('/'),
            'create' => Pages\CreateInventoryMove::route('/create'),
            'view' => Pages\ViewInventoryMove::route('/{record}'),
            'edit' => Pages\EditInventoryMove::route('/{record}/edit'),
        ];
    }
}
