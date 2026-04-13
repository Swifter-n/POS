<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BusinessResource\Pages;
use App\Filament\Resources\BusinessResource\RelationManagers;
use App\Models\Business;
use App\Models\Outlet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class BusinessResource extends Resource
{
    protected static ?string $model = Business::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Businesses';
    protected static ?string $navigationGroup = 'Business Management';

    public static function getEloquentQuery(): Builder
{
    $user = Auth::user();

    // 1. Principal (business_id-nya NULL) bisa melihat semua bisnis.
    if (is_null($user->business_id)) {
        return parent::getEloquentQuery();
    }

    $query = parent::getEloquentQuery();

    // 2. Staf Outlet (punya outlet_id) hanya bisa melihat bisnis tempat outlet-nya berada.
    if (!is_null($user->outlet_id)) {
        $outlet = Outlet::find($user->outlet_id);
        // Filter tabel 'businesses' DI MANA kolom 'id'-nya cocok dengan business_id dari outlet staf.
        return $query->where('id', $outlet?->business_id);
    }

    // 3. Pemilik Bisnis (punya business_id).
    // Filter tabel 'businesses' DI MANA kolom 'id'-nya cocok dengan business_id milik user.
    return $query->where('id', $user->business_id);
}

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Business Name')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('address')
                    ->required()
                    ->maxLength(500)
                    ->label('Business Address')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('phone')
                    ->required()
                    ->maxLength(15)
                    ->numeric()
                    ->label('Business Phone')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('bank_name')
                    ->required()
                    ->maxLength(100)
                    ->label('Bank Name')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('acc_bank')
                    ->required()
                    ->maxLength(50)
                    ->label('Account Bank')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('status')
                    ->label('Status')
                    ->columnSpanFull()
                    ->live(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->label('Business Name'),
                Tables\Columns\TextColumn::make('address')
                    ->sortable()
                    ->searchable()
                    ->label('Business Address'),
                Tables\Columns\TextColumn::make('phone')
                    ->sortable()
                    ->searchable()
                    ->label('Business Phone'),
                Tables\Columns\TextColumn::make('bank_name')
                    ->sortable()
                    ->searchable()
                    ->label('Bank Name'),
                Tables\Columns\TextColumn::make('acc_bank')
                    ->sortable()
                    ->searchable()
                    ->label('Bank Name'),
                Tables\Columns\ToggleColumn::make('status')
                    ->onIcon('heroicon-o-check-circle')
                    ->offIcon('heroicon-o-x-circle')
                    ->sortable()
                    ->label('Status'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d-M-Y')
                    ->label('Created Date')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                ->hidden(fn () => Auth::user()->role_id === '1'),
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
            'index' => Pages\ListBusinesses::route('/'),
            'create' => Pages\CreateBusiness::route('/create'),
            'edit' => Pages\EditBusiness::route('/{record}/edit'),
        ];
    }
}
