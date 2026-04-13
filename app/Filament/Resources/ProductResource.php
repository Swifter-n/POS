<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Outlet;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Products';
    protected static ?string $navigationGroup = 'Product Management';


public static function getEloquentQuery(): Builder
{
    $user = Auth::user();

    // 1. Principal (Super Admin) - business_id-nya NULL, bisa melihat semua produk.
    if (is_null($user->business_id)) {
        return parent::getEloquentQuery();
    }

    // Ambil query dasar untuk kita modifikasi
    $query = parent::getEloquentQuery();

    // 2. Staf Outlet - punya outlet_id, hanya bisa melihat produk dari bisnis tempat outlet-nya berada.
    if (!is_null($user->outlet_id)) {
        // Cari dulu outlet tempat user ini bekerja
        $outlet = Outlet::find($user->outlet_id);

        // Filter produk berdasarkan business_id dari outlet tersebut
        // Tanda tanya (?) adalah null-safe operator, untuk mencegah error jika outlet tidak ditemukan
        return $query->where('business_id', $outlet?->business_id);
    }

    // 3. Pemilik Bisnis - punya business_id tapi tidak punya outlet_id.
    // Bisa melihat semua produk di dalam bisnisnya.
    return $query->where('business_id', $user->business_id);
}

public static function form(Form $form): Form
{
    return $form
        ->schema([
            // --- GRUP KIRI (LEBIH LEBAR) ---
            Forms\Components\Group::make()
                ->schema([
                    Section::make('Product Details')
                        ->schema([
                            Forms\Components\Select::make('product_type')
                                ->options([
                                    'finished_good' => 'Finished Good',
                                    'raw_material' => 'Raw Material',
                                ])
                                ->required()->live(),
                            Forms\Components\TextInput::make('name')->required()->maxLength(50),
                            Forms\Components\TextInput::make('material_code')->required()->maxLength(25),
                            Forms\Components\TextInput::make('sku')->required()->maxLength(6),
                            Forms\Components\Select::make('category_id')->relationship('category', 'name')->required(),
                            Forms\Components\Select::make('brand_id')
                                ->relationship('brand', 'name')
                                ->hidden(fn (Get $get) => $get('product_type') === 'raw_material'),
                            Forms\Components\TextInput::make('base_uom')
                                    ->label('Base Unit of Measure')
                                    ->helperText('Satuan terkecil untuk stok. Cth: PCS, gram, ml.')
                                    ->live()
                                    ->required(),
                            Forms\Components\TextInput::make('min_sled_days')
                                    ->numeric()
                                    ->label('Minimum SLED Days on Receipt')
                                    ->default(0)
                                    ->helperText('Minimum sisa umur simpan (hari) saat barang diterima dari supplier. Biarkan 0 jika tidak ada aturan.'),
                            Forms\Components\TextInput::make('calories')
                                    ->label('Calories'),
                            Forms\Components\Textarea::make('description')->columnSpanFull(),
                            
                        ])->columns(2),

                    Section::make('Pricing Information')
                        ->schema([
                            Forms\Components\TextInput::make('cost')->required()->numeric()->prefix('Rp'),
                            Forms\Components\TextInput::make('price')
                                ->hidden(fn (Get $get) => $get('product_type') === 'raw_material')
                                ->required()->numeric()->prefix('Rp')->live(onBlur: true),
                            // Forms\Components\Toggle::make('is_promo')
                            //     ->hidden(fn (Get $get) => $get('product_type') === 'raw_material')
                            //     ->live(),
                            // Forms\Components\Select::make('percent')
                            //     ->options([10 => '10%', 20 => '20%', 30 => '30%', 40 => '40%', 50 => '50%'])
                            //     ->live()
                            //     ->hidden(fn (Get $get) => !$get('is_promo') || $get('product_type') === 'raw_material')
                            //     ->afterStateUpdated(function (Get $get, Set $set) {
                            //         $price = floatval($get('price'));
                            //         $percent = intval($get('percent'));
                            //         if ($price > 0 && $percent > 0) {
                            //             $discount = ($price * $percent) / 100;
                            //             $set('price_afterdiscount', $price - $discount);
                            //         }
                            //     }),
                            // Forms\Components\TextInput::make('price_afterdiscount')
                            //     ->readOnly()->numeric()->prefix('Rp')
                            //     ->hidden(fn (Get $get) => !$get('is_promo') || $get('product_type') === 'raw_material'),
                        ])->columns(2),
                    Forms\Components\Section::make('Warehouse Management (WMS)')
                        ->schema([
                            Forms\Components\Select::make('target_zone_id')
                                ->label('Direct Putaway Zone (Prioritas 1)')
                                ->relationship('targetZone', 'name') // Pastikan relasi targetZone ada di Model Product
                                ->searchable()
                                ->preload()
                                ->helperText('Jika diisi, sistem akan SELALU mencoba menaruh barang di zona ini dulu sebelum cek Rules.'),

                            Forms\Components\Select::make('storage_condition')
                                ->label('Storage Condition (Untuk Rules)')
                                ->options([
                                    'FAST_MOVING' => 'Fast Moving',
                                    'SLOW_MOVING' => 'Slow Moving',
                                    'COLD' => 'Cold Storage',
                                    'DRY' => 'Dry Storage',
                                    'FRAGILE' => 'Fragile',
                                    'HAZARDOUS' => 'Hazardous',
                                ])
                                ->searchable()
                                ->helperText('Digunakan oleh Putaway Rules untuk menentukan zona secara dinamis.'),
                        ])->columns(2)->collapsed(),

                    // --- FIELD WEIGHT & VOLUME  ---
                    Forms\Components\Section::make('Physical Attributes')
                            ->description('Atribut fisik per 1 (satu) unit dari Base UoM.')
                            ->schema([
                                Forms\Components\TextInput::make('weight_kg')
                                    ->numeric()
                                    ->label(fn (Get $get): string => 'Weight (KG) per 1 ' . strtoupper($get('base_uom') ?: 'Base UoM'))
                                    ->default(0),

                                Forms\Components\TextInput::make('volume_cbm')
                                    ->numeric()
                                    ->label(fn (Get $get): string => 'Volume (CBM) per 1 ' . strtoupper($get('base_uom') ?: 'Base UoM'))
                                    ->default(0),
                            ])->columns(2),
                ])
                ->columnSpan(['lg' => 2]),

            // --- GRUP KANAN (LEBIH SEMPIT) ---
            Forms\Components\Group::make()
                ->schema([
                    Section::make('Images & Status')
                        ->schema([
                            Forms\Components\FileUpload::make('thumbnail')->image()->required(),
                            Forms\Components\Toggle::make('status')->required()->default(true),
                            Forms\Components\Toggle::make('is_popular')
                                ->hidden(fn (Get $get) => $get('product_type') === 'raw_material')
                                ->default(false),
                            Forms\Components\Toggle::make('is_sellable_pos')
                                ->label('Sellable in POS')
                                ->helperText('Tentukan apakah produk ini muncul di katalog POS untuk dijual.')
                                ->required(),
                        ]),

                    Section::make('Identifiers & Relations')
                        ->schema([
                            Forms\Components\TextInput::make('rating')->numeric()->minValue(0)
                                ->hidden(fn (Get $get) => $get('product_type') === 'raw_material'),
                            Forms\Components\TextInput::make('barcode')->required()->maxLength(255),

                        ]),
                ])
                ->columnSpan(['lg' => 1]),
        ])
        ->columns(3);
}

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->label('Name'),
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable()
                    ->searchable()
                    ->label('Brand'),
                Tables\Columns\TextColumn::make('category.name')
                    ->sortable()
                    ->searchable()
                    ->label('Category'),

                Tables\Columns\IconColumn::make('is_popular')
                    ->boolean()
                    ->label('Popular')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable()
                    ->searchable()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),
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
            RelationManagers\AddonsRelationManager::class,
            RelationManagers\ProductSizesRelationManager::class,
            RelationManagers\ProductPhotosRelationManager::class,
            RelationManagers\ProductIngredientsRelationManager::class,
            RelationManagers\UomsRelationManager::class,
            RelationManagers\RecipesRelationManager::class,
            RelationManagers\PurchaseOrderItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
