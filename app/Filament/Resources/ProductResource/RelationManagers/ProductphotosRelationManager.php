<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductphotosRelationManager extends RelationManager
{
    protected static string $relationship = 'productphotos';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('photo')
                    ->image()
                    ->required()
                    ->label('Photo Product')
                    ->disk('public') // Pastikan disimpan di disk public
                    ->directory('product-photos'), // Simpan di folder yang rapi
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('photo')
            ->columns([
                Tables\Columns\ImageColumn::make('photo'),
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
