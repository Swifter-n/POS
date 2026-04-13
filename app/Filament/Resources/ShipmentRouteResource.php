<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShipmentRouteResource\Pages;
use App\Filament\Resources\ShipmentRouteResource\Pages\ViewShipmentRoute;
use App\Filament\Resources\ShipmentRouteResource\RelationManagers;
use App\Models\Area;
use App\Models\ShipmentRoute;
use App\Traits\HasPermissionChecks;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShipmentRouteResource extends Resource
{
    use HasPermissionChecks; // Gunakan trait helper jika perlu

    protected static ?string $model = ShipmentRoute::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 12;

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
        return self::userHasPermission('manage shipment routes');
    }

    public static function canCreate(): bool
    {
        return self::userHasPermission('manage shipment routes');
    }


    public static function getEloquentQuery(): Builder
    {
        // Filter berdasarkan business_id dari Plant
        return parent::getEloquentQuery()->whereHas('sourcePlant', fn($q) =>
            $q->where('business_id', Auth::user()->business_id)
        );
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Route Name')
                    ->required()
                    ->helperText('Contoh: Rute Jabodetabek, Rute Antar Pulau'),
                 Forms\Components\Select::make('source_plant_id')
                    ->relationship(
                        name: 'sourcePlant', // Gunakan relasi baru
                        titleAttribute: 'name',
                        // Filter Plant berdasarkan business_id
                        modifyQueryUsing: fn (Builder $query) => $query->where('business_id', Auth::user()->business_id)
                    )
                    ->label('Source Plant')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('base_cost')
                    ->label('Base Cost')
                    ->numeric()->prefix('Rp')->required()->default(0)
                    ->helperText('Biaya dasar untuk menjalankan rute ini.'),


            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('sourcePlant.name') // Gunakan relasi baru
                    ->label('From (Plant)')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('base_cost')->money('IDR'),
                Tables\Columns\TextColumn::make('destinationAreas.name') // Tampilkan daftar Area
                    ->label('To (Areas)')
                    ->listWithLineBreaks()
                    ->limitList(3),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                //Tables\Actions\ViewAction::make(),
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
            RelationManagers\DestinationAreasRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipmentRoutes::route('/'),
            'create' => Pages\CreateShipmentRoute::route('/create'),
            'edit' => Pages\EditShipmentRoute::route('/{record}/edit'),
            //'view' => Pages\ViewShipmentRoute::route('/{record}'),

        ];
    }
}
