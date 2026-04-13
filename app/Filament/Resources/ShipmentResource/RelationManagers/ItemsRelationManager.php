<?php

namespace App\Filament\Resources\ShipmentResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';


    public function form(Form $form): Form
    {
        return $form->schema([]); // Tetap read-only
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            // Query disederhanakan, hanya perlu memuat 'product'
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product'))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.sku') // Tambahkan SKU
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label('Qty to Ship (Base UoM)')
                    ->badge()
                    ->color('success')
                    ->numeric() // Tambahkan numeric untuk alignment
                    ->sortable()
                    // Format dengan UoM dasar produk
                    ->formatStateUsing(fn (Model $record) =>
                        // Pastikan $record->product dimuat
                        Number::format($record->quantity, 0) . ' ' . ($record->product?->base_uom ?? 'PCS')
                    ),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}

