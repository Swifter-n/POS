<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TermsOfPaymentResource\Pages;
use App\Filament\Resources\TermsOfPaymentResource\RelationManagers;
use App\Models\TermsOfPayment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TermsOfPaymentResource extends Resource
{
    protected static ?string $model = TermsOfPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = 'Master Data';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('days')->numeric()->required()->label('Number of Days'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('days')->numeric()->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTermsOfPayments::route('/'),
            'create' => Pages\CreateTermsOfPayment::route('/create'),
            'edit' => Pages\EditTermsOfPayment::route('/{record}/edit'),
        ];
    }
}
