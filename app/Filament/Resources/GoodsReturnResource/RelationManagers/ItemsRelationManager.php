<?php

namespace App\Filament\Resources\GoodsReturnResource\RelationManagers;

use App\Models\GoodsReturn;
use App\Models\GoodsReturnItem;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUom;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected function canCreate(): bool
    {
        return $this->getOwnerRecord()->status === 'draft';
    }
    protected function canEdit(Model $record): bool
    {
        return $this->getOwnerRecord()->status === 'draft';
    }
    protected function canDelete(Model $record): bool
    {
        return $this->getOwnerRecord()->status === 'draft';
    }


    // ==========================================================
    // --- FORM (BARU) ---
    // (Untuk input item retur manual)
    // ==========================================================
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // 1. Pilih Produk
                Select::make('product_id')
                    ->label('Product to Return')
                    ->relationship('product', 'name', fn(Builder $query) =>
                        $query->where('business_id', Auth::user()->business_id)
                    )
                    ->searchable()->preload()->required()->reactive()
                    ->columnSpan(2),

                // 2. Input Kuantitas & UoM
                TextInput::make('quantity')
                    ->numeric()->required()->minValue(1)
                    ->reactive(),
                Select::make('uom')
                    ->label('Unit')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        return ProductUom::where('product_id', $productId)
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()->reactive()
                    ->default(fn(Get $get) => Product::find($get('product_id'))?->base_uom ?? 'PCS'),

                // 3. Alasan
                Select::make('reason_code')
                    ->options([
                        'DAMAGED' => 'Damaged Goods',
                        'NEAR_EXPIRY' => 'Near Expiry',
                        'OVERSTOCK' => 'Overstock',
                        'WRONG_ITEM' => 'Wrong Item (Received)',
                        'OTHER' => 'Other',
                    ])
                    ->required(), // (Kita wajibkan reason_code di sini)

                Textarea::make('reason')
                    ->label('Reason Details (Notes)')
                    ->columnSpanFull(),
            ])
            ->columns(4);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')->searchable(),
                Tables\Columns\TextColumn::make('quantity')->label('Qty'),
                Tables\Columns\TextColumn::make('uom')->label('UoM'),
                Tables\Columns\TextColumn::make('reason_code')->badge(),
                Tables\Columns\TextColumn::make('reason')->label('Notes')->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Item to Return')
                    ->visible(fn(RelationManager $livewire) => $livewire->getOwnerRecord()->status === 'draft')
                    // (Tidak perlu mutate, data disimpan apa adanya)
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn(Model $record) => $this->getOwnerRecord()->status === 'draft'),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn(Model $record) => $this->getOwnerRecord()->status === 'draft'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                         ->visible(fn(RelationManager $livewire) => $livewire->getOwnerRecord()->status === 'draft'),
                ]),
            ]);
    }
}
