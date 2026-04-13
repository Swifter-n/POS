<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseTaskResource\Pages;
use App\Filament\Resources\WarehouseTaskResource\RelationManagers;
use App\Models\GoodsReceipt;
use App\Models\Shipment;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTask;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WarehouseTaskResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = StockTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationGroup = 'Inventory Management';
    protected static ?string $navigationLabel = 'Warehouse Tasks (Put-Away)';
    protected static ?string $slug = 'warehouse-tasks';

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

    public static function canCreate(): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function canViewAny(): bool
    {
        // Ganti permission jika perlu
        return self::userHasPermission('view putaway tasks');
    }
    public static function canEdit(Model $record): bool
    {
        // Izinkan edit untuk assign/eksekusi
        return self::userHasPermission('execute putaway tasks');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) return parent::getEloquentQuery()->whereRaw('0 = 1');

        $query = parent::getEloquentQuery()
            ->where('business_id', $user->business_id)
            ->where('transfer_type', 'put_away') // Filter Kunci
            ->with(['sourceLocation', 'plant', 'assignedUser']);

        if (self::userHasRole('Owner')) {
            return $query; // Owner bisa lihat semua
        }

        $userPlantId = null;
        $user->loadMissing('locationable');
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        }

        $query->where(function (Builder $q) use ($user, $userPlantId) {
            $canManage = self::userHasPermission('manage putaway tasks'); // Misal: Manager
            $canExecute = self::userHasPermission('execute putaway tasks'); // Misal: Staff

            // Jika Manager, tampilkan semua di Plant-nya
            if ($canManage && $userPlantId) {
                 $q->where('plant_id', $userPlantId);
            }

            // Jika Staff, tampilkan HANYA miliknya
            if ($canExecute) {
                 $q->orWhere('assigned_user_id', $user->id);
            }

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
                Tables\Columns\TextColumn::make('transfer_number')
                    ->label('Task #')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('source_doc_display')
                    ->label('Source')
                    ->getStateUsing(function (StockTransfer $record) {
                        // Polimorfik manual check
                        if ($record->sourceable_type === GoodsReceipt::class) {
                            return "GR: " . ($record->sourceable->receipt_number ?? 'N/A');
                        }
                        if ($record->sourceable_type === Shipment::class) { // Jika dari return shipment
                            return "DO: " . ($record->sourceable->shipment_number ?? 'N/A');
                        }
                        return 'N/A';
                    }),

                Tables\Columns\TextColumn::make('sourceLocation.name')
                    ->label('From (Area)')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'warning' => 'pending_pick',
                        'info' => 'in_progress',
                        'success' => 'completed',
                    ]),

                Tables\Columns\TextColumn::make('assignedUser.name')
                    ->label('Picker')
                    ->placeholder('Unassigned'),

                Tables\Columns\TextColumn::make('created_at')->date(),
            ])
            ->filters([
                 Tables\Filters\SelectFilter::make('plant_id')
                     ->relationship('plant', 'name')
                     ->searchable()
                     ->preload()
                     ->visible(fn() => self::userHasRole('Owner')),
                 Tables\Filters\SelectFilter::make('assigned_user_id')
                    ->label('Assigned To')
                    ->options(function () {
                        return User::where('business_id', Auth::user()->business_id)
                                   ->where('status', true)
                                   ->whereNotNull('name')
                                   ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Open Task'), // Arahkan ke EditWarehouseTask
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Tidak ada bulk actions
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //RelationManagers\ItemsRelationManager::class,
            RelationManagers\PutAwayEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseTasks::route('/'),
            'create' => Pages\CreateWarehouseTask::route('/create'),
            'edit' => Pages\EditWarehouseTask::route('/{record}/edit'),
        ];
    }
}
