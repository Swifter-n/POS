<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TableResource\Pages;
use App\Filament\Resources\TableResource\RelationManagers;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TableResource extends Resource
{
    protected static ?string $model = TableModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationLabel = 'Tables';
    protected static ?string $navigationGroup = 'Business Management';

    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }

    private static function userHasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        // Owner selalu boleh
        if (self::userHasRole('Owner')) return true;

        return DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }


    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user) return parent::getEloquentQuery()->whereRaw('0=1');

        $query = parent::getEloquentQuery();

        // OWNER → filter berdasarkan business_id
        if (self::userHasRole('Owner')) {
            if (!$user->business_id) return $query->whereRaw('0=1');

            return $query->whereHas('outlet', function (Builder $q) use ($user) {
                $q->where('business_id', $user->business_id);
            });
        }

        // KASIR/MANAGER → filter berdasarkan outlet_id
        if (!$user->outlet_id) return $query->whereRaw('0=1');

        return $query->where('outlet_id', $user->outlet_id);
    }

    /* --------------------------------------------------------
     | FORM
     | -------------------------------------------------------- */

    public static function form(Form $form): Form
    {
        $user = Auth::user();
        $hasSpecificOutlet = !is_null($user->outlet_id);

        return $form->schema([
            Select::make('outlet_id')
    ->label('Outlet')
    // relationship(nameRelation, displayColumn, queryCallback)
    ->relationship('outlet', 'name', function (Builder $query) use ($user, $hasSpecificOutlet) {
        // Jika user tidak punya outlet spesifik (owner), batasi outlet ke business owner
        if (! $hasSpecificOutlet) {
            $query->where('business_id', $user->business_id);
        }
    })
    ->required()
    ->default(fn () => $hasSpecificOutlet ? $user->outlet_id : null)
    ->disabled(fn () => $hasSpecificOutlet)
    ->placeholder($hasSpecificOutlet ? 'Outlet Anda' : 'Pilih Outlet'),

            TextInput::make('code')
                ->label('Nama Meja / Kode')
                ->required()
                ->maxLength(20),


            TextInput::make('x_position')
                ->label('X Position')
                ->numeric()
                ->readOnly()
                ->helperText('Diatur via aplikasi tablet/mobile.'),

            TextInput::make('y_position')
                ->label('Y Position')
                ->numeric()
                ->readOnly()
                ->helperText('Diatur via aplikasi tablet/mobile.'),

            TextInput::make('capacity')
                ->label('Kapasitas')
                ->required()
                ->maxLength(20),

            TextInput::make('qr_content')
                ->label('QR Code Value')
                ->columnSpanFull()
                ->helperText('Ini yang akan discan aplikasi Flutter.'),
            Select::make('status')
                ->options([
                    'available' => 'Available (Kosong)',
                    'occupied' => 'Occupied (Terisi)',
                ])
                ->required()
                ->default('available'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Auth::user();
        $hasSpecificOutlet = !is_null($user->outlet_id);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Nama Meja')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('capacity')
                    ->label('Kapasitas')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('x_position')
                    ->label('X Position')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('y_position')
                    ->label('Y Position')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'occupied' => 'danger',  // Merah
                        'available' => 'success', // Hijau
                        default => 'gray',
                    }),
            ])

            ->filters([
                Tables\Filters\SelectFilter::make('outlet_id')
                    ->label('Filter Outlet')
                    ->relationship('outlet', 'name', function (Builder $query) use ($user) {
                        return $query->where('business_id', $user->business_id);
                    })
                    ->hidden(fn () => $hasSpecificOutlet),
            ])

            ->modifyQueryUsing(function (Builder $query) use ($user, $hasSpecificOutlet) {
                if ($hasSpecificOutlet) {
                    $query->where('outlet_id', $user->outlet_id);
                } else {
                    $query->whereHas('outlet', fn ($q) =>
                        $q->where('business_id', $user->business_id)
                    );
                }
            })

            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => self::userHasPermission('table.edit')),
                Tables\Actions\DeleteAction::make()->visible(fn () => self::userHasPermission('table.delete')),
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => self::userHasPermission('table.delete')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTables::route('/'),
            'create' => Pages\CreateTable::route('/create'),
            'edit' => Pages\EditTable::route('/{record}/edit'),
        ];
    }
}
