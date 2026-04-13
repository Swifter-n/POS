<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FleetResource\Pages;
use App\Filament\Resources\FleetResource\RelationManagers;
use App\Models\Fleet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class FleetResource extends Resource
{
    protected static ?string $model = Fleet::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
         return $form->schema([
            Forms\Components\Select::make('ownership')->options(['internal' => 'Internal', 'vendor' => 'Vendor'])->required()->live(),
            Forms\Components\Select::make('vendor_id')
            ->relationship(
                name: 'vendor',
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query) => $query->where('type', 'Transporter')->where('business_id', Auth::user()->business_id)
            )
            ->searchable()
            ->preload()
            ->required()
            ->visible(fn (Get $get) => $get('ownership') === 'vendor'),
            Forms\Components\TextInput::make('vehicle_name')->required(),
            Forms\Components\TextInput::make('plate_number')->required()->unique(ignoreRecord: true),
            Forms\Components\Select::make('type')->options(['Truk' => 'Truk', 'Mobil Box' => 'Mobil Box', 'Motor' => 'Motor'])->required(),
            Forms\Components\Select::make('status')->options(['available' => 'Available', 'in_use' => 'In Use', 'maintenance' => 'Maintenance'])->required()->default('available'),
            Forms\Components\TextInput::make('max_load_kg')->numeric()->label('Max Load (KG)'),
            Forms\Components\TextInput::make('max_volume_cbm')->numeric()->label('Max Volume (CBM)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('vehicle_name')->searchable(),
            Tables\Columns\TextColumn::make('ownership'),
            Tables\Columns\TextColumn::make('vendor.name'),
            Tables\Columns\TextColumn::make('status')->badge()->colors(['success' => 'available', 'warning' => 'in_use', 'danger' => 'maintenance']),
            ])
            ->filters([
                //
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFleets::route('/'),
            'create' => Pages\CreateFleet::route('/create'),
            'edit' => Pages\EditFleet::route('/{record}/edit'),
        ];
    }
}
