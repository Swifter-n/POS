<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerServiceLevelResource\Pages;
use App\Filament\Resources\CustomerServiceLevelResource\RelationManagers;
use App\Models\CustomerServiceLevel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CustomerServiceLevelResource extends Resource
{
    protected static ?string $model = CustomerServiceLevel::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static ?string $navigationGroup = 'Master Data';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')->required()->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('priority_order')->numeric()->required()->helperText('Angka lebih kecil berarti prioritas lebih tinggi.'),
                Forms\Components\Textarea::make('description')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
               Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('priority_order')->sortable(),
            ])
            ->defaultSort('priority_order', 'asc')
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
            'index' => Pages\ListCustomerServiceLevels::route('/'),
            'create' => Pages\CreateCustomerServiceLevel::route('/create'),
            'edit' => Pages\EditCustomerServiceLevel::route('/{record}/edit'),
        ];
    }
}
