<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UomsRelationManager extends RelationManager
{
    protected static string $relationship = 'uoms';
    protected static ?string $recordTitleAttribute = 'uom_name';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('uom_type')
                ->label('UoM Type')
                ->options([
                    'purchasing' => 'Purchasing Unit (untuk Beli)',
                    'selling' => 'Selling Unit (untuk Jual)',
                    'production' => 'Production Unit (untuk Resep)',
                ])
                ->required(),
                Forms\Components\TextInput::make('uom_name')
                    ->label('Unit Name (e.g., CRT, PACK, KG)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('conversion_rate')
                    ->label('Conversion Rate to Base UoM')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->helperText('Contoh: Jika Base UoM adalah PCS dan ini adalah CRT isi 24, maka rate-nya 24.'),
                Forms\Components\TextInput::make('barcode')
                    ->label('Barcode (Optional)')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('uom_name')
            ->columns([
                Tables\Columns\TextColumn::make('uom_name'),
                Tables\Columns\TextColumn::make('barcode'),
                Tables\Columns\TextColumn::make('conversion_rate'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
