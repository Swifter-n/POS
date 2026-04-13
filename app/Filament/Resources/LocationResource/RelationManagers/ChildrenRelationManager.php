<?php

namespace App\Filament\Resources\LocationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChildrenRelationManager extends RelationManager
{
    protected static string $relationship = 'children';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Saat membuat 'anak', kita tidak perlu memilih induk lagi
                // karena sudah otomatis terhubung ke record yang sedang diedit.
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('code')
                    ->maxLength(10)
                    ->unique(ignoreRecord: true),

                Forms\Components\Select::make('type')
                    ->options([
                        'AREA' => 'Area',
                        'RACK' => 'Rack',
                        'BIN' => 'Bin',
                        'PALLET' => 'Pallet',
                    ])
                    ->required(),

                Forms\Components\Toggle::make('is_sellable')
                    ->required()
                    ->helperText('Aktifkan jika stok di lokasi anak ini boleh dijual.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('parent_id')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\IconColumn::make('is_sellable')->boolean(),
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
