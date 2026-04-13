<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PickingListResource\Pages;
use App\Filament\Resources\PickingListResource\Pages\ViewPickingList;
use App\Filament\Resources\PickingListResource\RelationManagers;
use App\Models\PickingList;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
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

class PickingListResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = PickingList::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationGroup = 'WMS (Outbound)';

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
    // KONTROL HAK AKSES & DATA
    // ==========================================================

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return true; }

    public static function canViewAny(): bool
    {
        return self::userHasPermission('view picking lists');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        if (self::userHasRole('Owner') || self::userHasRole('Manager Gudang')) {
            return parent::getEloquentQuery()
                ->whereHas('sourceable', fn($q) => $q->where('business_id', $user->business_id));
        }

        return parent::getEloquentQuery()->where('user_id', $user->id);
    }



    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('picking_list_number')->searchable(),
                Tables\Columns\TextColumn::make('sourceable.so_number')->label('From SO'),
                Tables\Columns\TextColumn::make('user.name')->label('Assigned Picker'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ]),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->actions([
                //Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->label('View Details'),
                //ViewPickingList::make()->label('View Details'),

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
            'index' => Pages\ListPickingLists::route('/'),
            'create' => Pages\CreatePickingList::route('/create'),
            'view' => Pages\ViewPickingList::route('/{record}'),
            'edit' => Pages\EditPickingList::route('/{record}/edit'),
        ];
    }
}
