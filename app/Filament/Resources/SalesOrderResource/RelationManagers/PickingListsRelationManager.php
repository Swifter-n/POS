<?php

namespace App\Filament\Resources\SalesOrderResource\RelationManagers;

use App\Filament\Resources\PickingListResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PickingListsRelationManager extends RelationManager
{
    protected static string $relationship = 'pickingLists';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('picking_list_number')
                    ->required()
                    ->maxLength(255),
            ]);
    }

public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('picking_list_number')
            ->columns([
                Tables\Columns\TextColumn::make('picking_list_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'pending',
                        'info' => 'in_progress',
                        'success' => 'completed',
                        'primary' => 'shipped', // Status setelah DO dibuat
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('user.name') // User yang di-assign
                    ->label('Assigned To'),
                Tables\Columns\TextColumn::make('warehouse.name') // Gudang sumber
                    ->label('From Warehouse'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // ==========================================================
                // --- HAPUS CreateAction::make() ---
                // (Picking List dibuat oleh Action 'generatePickingList' di SalesOrderResource)
                // ==========================================================
            ])
            ->actions([
                // ==========================================================
                // --- UBAH 'View' MENJADI 'Edit' ---
                // (Arahkan user ke halaman di mana mereka bisa 'Start Pick' / 'Input Qty')
                // ==========================================================
                Tables\Actions\Action::make('Process Task')
                    ->label('Process Task')
                    ->url(fn ($record): string => PickingListResource::getUrl('edit', ['record' => $record]))
                    ->icon('heroicon-m-arrow-right')
                    ->color('info')
                    // Tampilkan hanya jika task belum selesai
                    ->visible(fn ($record) => !in_array($record->status, ['completed', 'shipped', 'cancelled'])),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
