<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DebitNoteResource\Pages;
use App\Filament\Resources\DebitNoteResource\RelationManagers;
use App\Models\DebitNote;
use App\Models\PurchaseReturn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class DebitNoteResource extends Resource
{
    protected static ?string $model = DebitNote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-minus';
    protected static ?string $navigationGroup = 'Financials';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Debit Note Details')
                    ->schema([
                        Forms\Components\Select::make('supplier_id')
                            ->relationship('supplier', 'name')
                            ->searchable()->preload()->required()->live(),
                        Forms\Components\Select::make('purchase_return_id')
                            ->label('Reference Purchase Return')
                            ->options(fn (Forms\Get $get) => PurchaseReturn::where('supplier_id', $get('supplier_id'))->pluck('return_number', 'id'))
                            ->searchable(),
                        Forms\Components\DatePicker::make('note_date')->required()->default(now()),
                        Forms\Components\DatePicker::make('due_date')->required()->default(now()->addDays(30)),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Items')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship()
                            ->schema([
                                Forms\Components\Select::make('product_id')
                                    ->relationship('product', 'name')
                                    ->searchable()->preload()->required()->reactive(),
                                Forms\Components\TextInput::make('quantity')->numeric()->required()->default(1)->reactive(),
                                Forms\Components\TextInput::make('price_per_item')->label('Price per Item')->numeric()->required()->prefix('Rp')->reactive(),
                                Forms\Components\TextInput::make('reason')->required(),
                                Forms\Components\Placeholder::make('total_price')
                                    ->label('Total')
                                    ->content(fn (Forms\Get $get) => 'Rp ' . number_format((float)$get('quantity') * (float)$get('price_per_item'))),
                            ])
                            ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                $total = collect($get('items'))->sum(fn($item) => (float)$item['quantity'] * (float)$item['price_per_item']);
                                $set('total_amount', $total);
                            })
                            ->columns(4)->addActionLabel('Add Item'),
                    ]),

                Forms\Components\TextInput::make('total_amount')
                    ->label('Grand Total')
                    ->numeric()->readOnly()->prefix('Rp'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('debit_note_number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')->searchable(),
                Tables\Columns\TextColumn::make('total_amount')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('note_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->colors(['warning' => 'open', 'success' => 'applied', 'danger' => 'cancelled']),
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
            'index' => Pages\ListDebitNotes::route('/'),
            'create' => Pages\CreateDebitNote::route('/create'),
            'edit' => Pages\EditDebitNote::route('/{record}/edit'),
        ];
    }
}
