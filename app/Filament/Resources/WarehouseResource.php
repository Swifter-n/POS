<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseResource\Pages;
use App\Filament\Resources\WarehouseResource\RelationManagers;
use App\Models\Area;
use App\Models\District;
use App\Models\Province;
use App\Models\Regency;
use App\Models\Village;
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

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Master Data';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('parent_id')
                    ->label('Parent Warehouse')
                    ->relationship(
                        name: 'parent',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->where('business_id', Auth::user()->business_id)
                    )
                    ->helperText('Pilih gudang pusat jika ini adalah gudang cabang.'),
                Forms\Components\TextInput::make('code')->required(),
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\Select::make('type') // Field Tipe Gudang (PENTING)
                    ->options([
                        'RAW_MATERIAL' => 'Raw Material',
                        'FINISHED_GOOD' => 'Finished Good',
                        'DISTRIBUTION' => 'Distribution',
                        'COLD_STORAGE' => 'Cold Storage',
                        'MAIN' => 'Main/Central',
                        'OTHER' => 'Other',
                    ])
                    ->required(),
                Forms\Components\Toggle::make('is_main_warehouse')
                    ->helperText('Aktifkan jika ini adalah gudang pusat.'),
                Forms\Components\Toggle::make('status')
                    ->label('Status')
                    ->columnSpanFull()
                    ->live(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('area.name')->sortable(),
                Tables\Columns\TextColumn::make('parent.name')->label('Parent Warehouse'),
                Tables\Columns\IconColumn::make('is_main_warehouse')->boolean(),
                                Tables\Columns\ToggleColumn::make('status')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->sortable()
                    ->label('Status'),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('manage_locations')
        ->label('Manage Locations')
        ->icon('heroicon-o-map-pin')
        ->color('gray')
        ->url(fn (Warehouse $record): string => LocationResource::getUrl('index', ['tableFilters[warehouse][value]' => $record->id])),
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
            RelationManagers\LocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouses::route('/'),
            'create' => Pages\CreateWarehouse::route('/create'),
            'edit' => Pages\EditWarehouse::route('/{record}/edit'),
        ];
    }
}
