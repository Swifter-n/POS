<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PurchaseOrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrderItems';
    protected static ?string $title = 'Purchase Order History';

    public function form(Form $form): Form
    {
        return $form->schema([]);

    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('purchaseOrder.po_number')
                    ->label('PO Number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('purchaseOrder.vendor.name')
                    ->label('Supplier')
                    ->searchable(),
                Tables\Columns\TextColumn::make('purchaseOrder.order_date')
                    ->label('Order Date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric()
                    ->formatStateUsing(fn ($record) => "{$record->quantity} {$record->uom}"),
                Tables\Columns\TextColumn::make('price')
                    ->label('Purchase Price')
                    ->money('IDR')
                    ->sortable(),
            ])
            ->defaultSort('purchaseOrder.order_date', 'desc') // Urutkan dari yang terbaru
            // Nonaktifkan semua aksi karena ini adalah tabel riwayat (read-only)
            ->filters([
                //
            ])
            ->headerActions([
                //Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                //Tables\Actions\EditAction::make(),
                //Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                //Tables\Actions\BulkActionGroup::make([
                  //  Tables\Actions\DeleteBulkAction::make(),
                //]),
            ]);
    }
}
