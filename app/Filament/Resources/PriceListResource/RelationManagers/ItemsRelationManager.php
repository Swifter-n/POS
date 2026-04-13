<?php

namespace App\Filament\Resources\PriceListResource\RelationManagers;

use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    // ->relationship(
                    //     name: 'product',
                    //     titleAttribute: 'name',
                    //     // Filter agar hanya produk dari bisnis ini yang muncul
                    //     modifyQueryUsing: fn (Builder $query) => $query->where('business_id', Auth::user()->business_id)
                    // )
                     ->options(
                            fn () => Product::where('business_id', Auth::user()->business_id)->where('product_type', 'finished_good')
                                ->pluck('name', 'id')
                        )
                    ->searchable()
                    ->required()
                    // Pastikan satu produk tidak bisa ditambahkan dua kali dalam satu price list
                    ->unique(ignoreRecord: true, table: 'price_list_items', column: 'product_id')
                    ->label('Product'),
                Forms\Components\TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('Rp'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_id')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->searchable(),
                Tables\Columns\TextColumn::make('price')->money('IDR')->sortable(),
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
