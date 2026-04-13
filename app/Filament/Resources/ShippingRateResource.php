<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShippingRateResource\Pages;
use App\Filament\Resources\ShippingRateResource\RelationManagers;
use App\Models\Fleet;
use App\Models\ShippingRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ShippingRateResource extends Resource
{
    protected static ?string $model = ShippingRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Master Data';

     public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('from_area_id')
                    ->relationship('fromArea', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('to_area_id')
                    ->relationship('toArea', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Forms\Components\Select::make('vendor_id')
                    ->relationship(
                        name: 'vendor',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->where('type', 'Transporter')->where('business_id', Auth::user()->business_id)
                    )
                    ->searchable()
                    ->preload()
                    ->helperText('Biarkan kosong jika tarif ini untuk armada Internal.'),

                Forms\Components\Select::make('fleet_type')
                    ->label('Tipe Kendaraan')
                    ->options(
                        // Ambil semua tipe unik dari master data armada Anda
                        Fleet::distinct()->pluck('type', 'type')
                    )
                    ->helperText('Biarkan kosong jika tarif berlaku untuk semua tipe kendaraan.'),
                Forms\Components\TextInput::make('cost')
                    ->numeric()
                    ->prefix('Rp')
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fromArea.name')->label('From')->sortable(),
                Tables\Columns\TextColumn::make('toArea.name')->label('To')->sortable(),
                Tables\Columns\TextColumn::make('vendor.name')->placeholder('Internal'),
                Tables\Columns\TextColumn::make('fleet_type')->placeholder('All Types'),
                Tables\Columns\TextColumn::make('cost')->money('IDR')->sortable(),
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
            'index' => Pages\ListShippingRates::route('/'),
            'create' => Pages\CreateShippingRate::route('/create'),
            'edit' => Pages\EditShippingRate::route('/{record}/edit'),
        ];
    }
}
