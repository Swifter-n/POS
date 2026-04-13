<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RecipesRelationManager extends RelationManager
{
    protected static string $relationship = 'recipes';
    protected static ?string $recordTitleAttribute = 'raw_material_id';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('raw_material_id')
                    ->label('Raw Material')
                    ->options(
                        // Hanya tampilkan produk yang tipenya 'raw_material'
                        Product::where('product_type', 'raw_material')->pluck('name', 'id')
                    )
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('quantity_used')
                    ->numeric()->required(),
                Forms\Components\TextInput::make('uom')
                    ->label('Unit of Measure (e.g., gr, ml, pcs)')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('raw_material_id')
            ->columns([
                Tables\Columns\TextColumn::make('rawMaterial.name'),
                Tables\Columns\TextColumn::make('quantity_used'),
                Tables\Columns\TextColumn::make('uom'),
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
