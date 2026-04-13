<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VendorResource\Pages;
use App\Filament\Resources\VendorResource\RelationManagers;
use App\Models\Area;
use App\Models\Vendor;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?int $navigationSort = 10;

     public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                 Forms\Components\Select::make('type')
                    ->options([
                        'Supplier' => 'Supplier (Pemasok Barang)',
                        'Transporter' => 'Transporter (Logistik)',
                        'Service' => 'Service (Penyedia Jasa)',
                        'Other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\Select::make('area_id')
                ->label('Area')
                ->options(Area::pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->required(),
                Forms\Components\Select::make('purchasing_group_id')
                            ->relationship('purchasingGroup', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Purchasing Group'),
                Forms\Components\TextInput::make('contact_person')->maxLength(255),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(255),
                Forms\Components\Textarea::make('address')->columnSpanFull(),
                Forms\Components\TextInput::make('city')->maxLength(255),
                Forms\Components\TextInput::make('postal_code')->maxLength(255),
                Forms\Components\TextInput::make('bank_name')->maxLength(255),
                Forms\Components\TextInput::make('bank_account_number')->maxLength(255),
                Forms\Components\TextInput::make('tax_id')->label('Tax ID (NPWP)')->maxLength(255),
                Forms\Components\Toggle::make('status')->required()->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('type')->searchable(),
                Tables\Columns\TextColumn::make('purchasingGroup.name')
                    ->label('Group')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('contact_person')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\IconColumn::make('status')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('purchasing_group_id')
                    ->label('Purchasing Group')
                    ->relationship('purchasingGroup', 'name')
                    ->preload(),
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
            'index' => Pages\ListVendors::route('/'),
            'create' => Pages\CreateVendor::route('/create'),
            'edit' => Pages\EditVendor::route('/{record}/edit'),
        ];
    }
}
