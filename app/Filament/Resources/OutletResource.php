<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OutletResource\Pages;
use App\Filament\Resources\OutletResource\RelationManagers;
use App\Models\District;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\PriceList;
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

class OutletResource extends Resource
{
    protected static ?string $model = Outlet::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Outlets';
    protected static ?string $navigationGroup = 'Business Management';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('area_id')
                    ->relationship('area', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('code')->required(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Outlet Name'),
                Forms\Components\Select::make('ownership_type')
                    ->options([
                        'internal' => 'Internal (Milik Sendiri)',
                        'franchise' => 'Franchise (Mitra)',
                    ])
                    ->required(),
                Forms\Components\Select::make('supplying_plant_id')
                    ->options(
                    // Ambil semua gudang dari bisnis user yang login
                    Plant::where('business_id', Auth::user()->business_id)
                             ->pluck('name', 'id')
                )
                    ->label('Supplying from')
                    ->helperText('Pilih gudang/DC yang akan menyuplai stok ke outlet ini.')
                    ->searchable()
                    ->preload(),

                Forms\Components\Select::make('price_list_id')
                    ->options(
                    // Ambil semua price list dari bisnis user yang login
                    PriceList::where('business_id', Auth::user()->business_id)
                             ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->preload()
                    ->label('Price List')
                    ->helperText('Daftar harga yang berlaku di outlet ini.'),
                Forms\Components\Select::make('province_id')
                    ->searchable()
                    ->preload()
                    ->options(Province::pluck('name', 'id'))
                    ->afterStateUpdated(fn (callable $set) => $set('regency_id', null))
                    ->live(), // Pemicu untuk dropdown di bawahnya

                Forms\Components\Select::make('regency_id')
                    ->options(fn (Get $get) => Regency::where('province_id', $get('province_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->afterStateUpdated(fn (callable $set) => $set('district_id', null))
                    ->live(), // Pemicu untuk dropdown di bawahnya

                Forms\Components\Select::make('district_id')
                    ->options(fn (Get $get) => District::where('regency_id', $get('regency_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->afterStateUpdated(fn (callable $set) => $set('village_id', null))
                    ->live(), // Pemicu untuk dropdown di bawahnya

                Forms\Components\Select::make('village_id')
                    ->options(fn (Get $get) => Village::where('district_id', $get('district_id'))->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->required(),

                Forms\Components\Textarea::make('address')
                    ->label('Detail Alamat (Jalan, Nomor, dll)')->columnSpanFull(),

                Forms\Components\TextInput::make('phone')
                    ->required()
                    ->maxLength(20)
                    ->label('Outlet Phone')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('description')
                    ->required()
                    ->maxLength(255)
                    ->label('Outlet Description')
                    ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->label('Outlet Name'),
                Tables\Columns\TextColumn::make('ownership_type')->badge()->colors(['success' => 'internal', 'warning' => 'franchise']),
                Tables\Columns\TextColumn::make('supplyingPlant.name')->sortable(),
                Tables\Columns\TextColumn::make('address')
                    ->sortable()
                    ->searchable()
                    ->label('Outlet Address'),
                Tables\Columns\TextColumn::make('phone')
                    ->sortable()
                    ->searchable()
                    ->label('Outlet Phone'),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description'),
                Tables\Columns\ToggleColumn::make('status')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->sortable()
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d-M-Y')
                    ->label('Created Date')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                ->hidden(fn () => Auth::user()->role_id === '1'),
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
            'index' => Pages\ListOutlets::route('/'),
            'create' => Pages\CreateOutlet::route('/create'),
            'edit' => Pages\EditOutlet::route('/{record}/edit'),
        ];
    }
}
