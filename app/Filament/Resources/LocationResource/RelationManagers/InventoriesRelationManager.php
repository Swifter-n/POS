<?php

namespace App\Filament\Resources\LocationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class InventoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')->relationship('product', 'name')->required(),
                Forms\Components\TextInput::make('batch')->required(),
                Forms\Components\DatePicker::make('sled')->required(),
                Forms\Components\TextInput::make('avail_stock')->numeric()->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('location_id')
            ->columns([
                Tables\Columns\TextColumn::make('product.name'),
                Tables\Columns\TextColumn::make('batch'),
                Tables\Columns\TextColumn::make('sled')->date(),
                Tables\Columns\TextColumn::make('avail_stock'),
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
