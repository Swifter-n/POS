<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockTransferResource\Pages;
use App\Filament\Resources\StockTransferResource\RelationManagers;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Support\HtmlString;

class StockTransferResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = StockTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
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
        // Gunakan helper STATIS
        return self::userHasPermission('view stock transfers');
    }

    public static function canCreate(): bool
    {
         // Gunakan helper STATIS
        return self::userHasPermission('create stock transfers');
    }

       public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) return parent::getEloquentQuery()->whereRaw('0 = 1');
        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);


        if (!$user->hasRole('Owner')) {
            $userPlantId = null;
            $userLocationableId = $user->locationable_id;
            $userLocationableType = $user->locationable_type;
            if ($userLocationableType === Warehouse::class && $user->locationable?->plant_id) {
                $userPlantId = $user->locationable->plant_id;
            }
            $query->where(function (Builder $q) use ($userPlantId, $userLocationableId, $userLocationableType, $user) {
                if ($userPlantId) {
                    // Hanya tampilkan STO/Sortir
                     $q->where('source_plant_id', $userPlantId)
                       ->orWhere('destination_plant_id', $userPlantId);
                     // Hapus logika 'PA-' dari sini
                }
                if ($userLocationableType === Outlet::class && $userLocationableId) {
                    $q->orWhereHasMorph('destination', [Outlet::class], fn (Builder $subQ) => $subQ->where('id', $userLocationableId));
                }

                // Hapus pengecekan 'assigned_user_id' (itu untuk PutAway)

                if (!$userPlantId && !$userLocationableId) {
                     $q->whereRaw('0 = 1'); // Sembunyikan jika tidak punya lokasi
                }
            });
        }

        $query->where(function (Builder $q) {
             $q->where('transfer_number', 'not like', 'PA-%') // JANGAN tampilkan PutAway
               ->orWhereNull('transfer_number'); // Jaga-jaga jika ada yg null
        });

        return $query;
    }

     public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transfer_number')->searchable(),
                Tables\Columns\TextColumn::make('display_source')
                    ->label('From')
                    ->getStateUsing(function (StockTransfer $record) {
                        if ($record->sourceLocation) {
                             $record->loadMissing('sourceLocation.locatable');
                             return ($record->sourceLocation->locatable?->name ?? '') . ' > ' . $record->sourceLocation->name;
                        }
                        if ($record->sourcePlant) return $record->sourcePlant->name . ' (Plant)';
                        return 'N/A';
                    }),
                 Tables\Columns\TextColumn::make('display_destination')
                    ->label('To')
                    ->getStateUsing(function (StockTransfer $record) {
                        // (Logika isPutAwayTask tidak relevan lagi di sini)
                        if ($record->destinationPlant) return $record->destinationPlant->name . ' (Plant)';
                        if ($record->destinationOutlet) return $record->destinationOutlet->name . ' (Outlet)';
                        if ($record->destinationLocation) {
                             $record->loadMissing('destinationLocation.locatable');
                             return ($record->destinationLocation->locatable?->name ?? '') . ' > ' . $record->destinationLocation->name;
                        }
                        return 'N/A';
                    }),
                Tables\Columns\TextColumn::make('status')->badge()
                     ->colors([
                        'gray' => 'draft',
                        'warning' => 'pending_approval',
                        // Hapus status PutAway
                        'success' => ['approved', 'completed', 'fully_received'],
                        'info' => 'processing',
                        'info' => 'ready_to_ship',
                        'primary' => 'shipped',
                        'danger' => ['rejected', 'cancelled'],
                    ])
                     ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('request_date')->date()->sortable(),
            ])
            ->filters([
                 Tables\Filters\SelectFilter::make('source_plant_id')
                     ->label('Source Plant')
                     ->relationship('sourcePlant', 'name')
                     ->searchable()
                     ->preload(),
                 Tables\Filters\SelectFilter::make('destination_plant_id')
                     ->label('Destination Plant')
                     ->relationship('destinationPlant', 'name')
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
            'index' => Pages\ListStockTransfers::route('/'),
            'create' => Pages\CreateStockTransfer::route('/create'),
            // Halaman Edit akan menggunakan form kondisional yg kita buat
            'edit' => Pages\EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
