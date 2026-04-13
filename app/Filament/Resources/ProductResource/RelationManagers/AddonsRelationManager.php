<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AddonsRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';
    protected static ?string $title = 'Add-Ons';
    protected static ?string $inverseRelationship = 'parentProducts';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // We won't allow creating new Add-on Products from here, only attacking existing ones
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Add-On Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('pivot.price')
                    ->label('Extra Price')
                    ->numeric()
                    ->prefix('Rp '),
                Tables\Columns\IconColumn::make('pivot.is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('Select Product as Add-On'),
                        Forms\Components\TextInput::make('price')
                            ->label('Adjust Price (Rp)')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->helperText('This is the extra price added when selecting this add-on. Can be 0 if the add-on is free.'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
