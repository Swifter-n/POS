<?php

namespace App\Filament\Resources\BomResource\RelationManagers;

use App\Models\ProductUom;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('product_id')
                    ->label('Component Product')
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name',
                        // Komponen bisa berupa RM, FG (seperti Biji Kopi Sangrai), atau Merchandise
                        modifyQueryUsing: fn (Builder $query) =>
                            $query->where('business_id', Auth::user()->business_id)
                                  ->whereIn('product_type', ['raw_material', 'finished_good', 'merchandise'])
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->reactive()
                    ->afterStateUpdated(fn (Set $set) => $set('uom', null)) // Reset UoM saat produk berubah
                    ->columnSpan(2),

                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->required()
                    ->helperText('Jumlah yang dibutuhkan untuk membuat 1 unit parent.'),

                Select::make('uom')
                    ->label('Unit')
                    ->options(function (Get $get): array {
                        $productId = $get('product_id');
                        if (!$productId) return [];
                        // Ambil semua UoM (termasuk purchasing, selling, production)
                        return ProductUom::where('product_id', $productId)
                            ->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()
                    ->searchable(),

                // ==========================================================
                // --- INI ADALAH FIELD KUNCI (SESUAI IDE ANDA) ---
                // ==========================================================
                Select::make('usage_type')
                    ->label('Usage Type')
                    ->options([
                        // 'CONSUMPTION' atau 'RAW_MATERIAL' untuk konsumsi standar pabrik
                        'RAW_MATERIAL' => 'Standard Consumption (RM)',
                        // 'RAW_MATERIAL_STORE' untuk item (seperti FG Beans)
                        // yang dikonsumsi sebagai RM di Outlet/Cafe
                        'RAW_MATERIAL_STORE' => 'Store Consumption (RM at Outlet)',
                        // Anda bisa tambahkan tipe lain nanti
                        'BY_PRODUCT' => 'By-product (Hasil Sampingan)',
                    ])
                    ->default('RAW_MATERIAL')
                    ->required()
                    ->helperText('Tentukan bagaimana komponen ini digunakan dalam BOM.')
                    ->columnSpan(2),
                // ==========================================================
            ])
            ->columns(4);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->numeric(),
                Tables\Columns\TextColumn::make('uom'), // Tampilkan UoM yang disimpan
                Tables\Columns\TextColumn::make('usage_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucwords(strtolower(str_replace('_', ' ', $state))))
                    ->color(fn (string $state): string => match ($state) {
                        'RAW_MATERIAL' => 'success',
                        'RAW_MATERIAL_STORE' => 'info',
                        'BY_PRODUCT' => 'warning',
                        default => 'gray',
                    }),
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
