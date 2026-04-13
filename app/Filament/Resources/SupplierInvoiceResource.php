<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierInvoiceResource\Pages;
use App\Filament\Resources\SupplierInvoiceResource\RelationManagers;
use App\Models\SupplierInvoice;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class SupplierInvoiceResource extends Resource
{
    protected static ?string $model = SupplierInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-down';
    protected static ?string $navigationGroup = 'Financials';

    // Invoice tidak dibuat manual, tapi otomatis dari event
    public static function canCreate(): bool { return false; }
    // Invoice tidak bisa diedit setelah dibuat
    public static function canEdit(Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    /**
     * Infolist untuk menampilkan detail di halaman View.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Invoice Details')
                    ->schema([
                        TextEntry::make('invoice_number'),
                        TextEntry::make('supplier.name'),
                        TextEntry::make('invoice_date')->date(),
                        TextEntry::make('status')->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'unpaid' => 'warning',
                                'paid' => 'success',
                                default => 'gray',
                            }),
                        TextEntry::make('total_amount')->money('IDR'),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')->searchable(),
                Tables\Columns\TextColumn::make('supplier.name')->searchable(),
                Tables\Columns\TextColumn::make('total_amount')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('invoice_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->colors([
                        'warning' => 'unpaid',
                        'success' => 'paid',
                    ]),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
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
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupplierInvoices::route('/'),
            'create' => Pages\CreateSupplierInvoice::route('/create'),
            'view' => Pages\ViewSupplierInvoice::route('/{record}'),
            'edit' => Pages\EditSupplierInvoice::route('/{record}/edit'),
        ];
    }
}
