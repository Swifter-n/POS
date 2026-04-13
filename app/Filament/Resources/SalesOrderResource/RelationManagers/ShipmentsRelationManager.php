<?php

namespace App\Filament\Resources\SalesOrderResource\RelationManagers;

use App\Filament\Resources\ShipmentResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    public function form(Form $form): Form
    {
        return $form
            ->schema([

            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('shipment_number')
            ->columns([
                Tables\Columns\TextColumn::make('shipment_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'ready_to_ship',
                        'warning' => 'shipping',
                        'success' => ['received', 'delivered'],
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('picker.name') // User yang pick (dari PL)
                    ->label('Picker'),
                Tables\Columns\TextColumn::make('created_at') // Kapan DO dibuat
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            // ==========================================================
            // --- PERBAIKAN: Hapus CreateAction ---
            // =Setting Shipment hanya dibuat dari Workbench
            // ==========================================================
            ->headerActions([
                // Tables\Actions\CreateAction::make(), // <-- DIHAPUS
            ])
            // ==========================================================
            ->actions([
                // Action 'View' Anda sudah benar, mengarah ke Edit (View) Shipment
                Tables\Actions\Action::make('View')
                    ->url(fn ($record): string => ShipmentResource::getUrl('edit', ['record' => $record]))
                    ->icon('heroicon-m-eye')
                    ->color('gray'), // Ganti warna
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
