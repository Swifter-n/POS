<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiscountRuleResource\Pages;
use App\Filament\Resources\DiscountRuleResource\RelationManagers;
use App\Models\Brand;
use App\Models\Channel;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class DiscountRuleResource extends Resource
{
    protected static ?string $model = DiscountRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Sales Management';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('business_id', Auth::user()->business_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // -----------------------------------------------------------
                // 1. DEFINISI DASAR
                // -----------------------------------------------------------
                Forms\Components\Section::make('Rule Definition')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Promo')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Select::make('applicable_for')
                            ->label('Channel Berlaku')
                            ->options([
                                'sales_order' => 'Sales Order (B2B)',
                                'pos' => 'Point of Sale (POS)',
                                'all' => 'All Channels',
                            ])
                            ->required()
                            ->default('sales_order')
                            ->live(), // Live update untuk trigger filter di bawah

                        Forms\Components\TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->label('Prioritas')
                            ->helperText('Angka kecil (0, 1) dijalankan lebih dulu.'),
                    ])->columns(3),

                // -----------------------------------------------------------
                // 2. TARGET PELANGGAN (SHARED: B2B & POS TIER)
                // -----------------------------------------------------------
                Forms\Components\Section::make('Target Customer / Member')
                    ->description('Atur target spesifik pelanggan (B2B) atau Member Tier (POS).')
                    ->schema([
                        // Field Khusus B2B (Hidden di POS)
                        Forms\Components\Select::make('customer_channel')
                            ->label('Customer Channel (B2B Only)')
                            ->options(Channel::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                            ->searchable()
                            ->hidden(fn (Get $get) => $get('applicable_for') === 'pos'),

                        // === UPDATE: Filter Dinamis Priority Level ===
                        Forms\Components\Select::make('priority_level_id')
                            ->relationship(
                                name: 'priorityLevel',
                                titleAttribute: 'name',
                                // Filter query berdasarkan pilihan 'applicable_for'
                                modifyQueryUsing: function (Builder $query, Get $get) {
                                    $query->where('business_id', Auth::user()->business_id);

                                    $channel = $get('applicable_for');
                                    if ($channel === 'pos') {
                                        // Jika POS, ambil scope 'pos' (Silver, Gold, Platinum)
                                        $query->where('scope', 'pos');
                                    } elseif ($channel === 'sales_order') {
                                        // Jika SO, ambil scope 'sales_order' (VIP, ONO, dll)
                                        $query->where('scope', 'sales_order');
                                    }
                                    // Jika 'all', tampilkan semua
                                    return $query;
                                }
                            )
                            ->label('Priority Level / Member Tier')
                            ->helperText(fn (Get $get) => $get('applicable_for') === 'pos'
                                ? 'Pilih Tier Member (Silver/Gold/Platinum) yang berhak.'
                                : 'Pilih Level Customer B2B.')
                            ->searchable()
                            ->preload()
                            // Reset value jika channel berubah agar tidak error ID
                            ->live(),

                        // Field Khusus B2B (Hidden di POS)
                        Forms\Components\Select::make('customer_id')
                            ->label('Specific Customer (B2B Only)')
                            ->options(Customer::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                            ->searchable()
                            ->hidden(fn (Get $get) => $get('applicable_for') === 'pos'),
                    ])
                    ->columns(3)
                    ->visible(fn (Get $get) => in_array($get('applicable_for'), ['sales_order', 'pos', 'all'])),

                // -----------------------------------------------------------
                // 3. TARGET PRODUK (SHARED: B2B & POS)
                // -----------------------------------------------------------
                Forms\Components\Section::make('Target Product & Quantity')
                    ->description('Berlaku untuk B2B dan POS (Item Level). Kosongkan jika menggunakan aturan kompleks POS.')
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Specific Product')
                            ->options(Product::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                            ->searchable(),

                        Forms\Components\Select::make('brand_id')
                            ->label('Specific Brand')
                            ->options(Brand::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                            ->searchable(),

                        Forms\Components\TextInput::make('min_quantity')
                            ->numeric()
                            ->label('Minimum Quantity'),

                        Forms\Components\Select::make('min_quantity_uom')
                            ->label('Unit of Measure')
                            ->options([
                                'PCS' => 'PCS',
                                'BOX' => 'BOX',
                                'CRT' => 'CRT',
                                'KG' => 'KG'
                            ]),
                    ])
                    ->columns(2),

                // -----------------------------------------------------------
                // 4. ATURAN KOMPLEKS POS (KHUSUS POS)
                // -----------------------------------------------------------
                Forms\Components\Section::make('Advanced POS Rules')
                    ->description('Gunakan ini untuk BOGO, Min. Belanja Total, atau Bundling.')
                    ->schema([
                         Forms\Components\Select::make('type')
                            ->label('Rule Type')
                            ->options([
                                'minimum_purchase' => 'Minimum Purchase (Total Belanja)',
                                'bogo_same_item' => 'BOGO (Buy X Get Y - Same Item)',
                                'category_discount' => 'Category Discount',
                                'buy_x_get_y' => 'Buy X Get Y (Different Item)',
                            ])
                            ->helperText('Jika diisi, aturan Produk di atas mungkin diabaikan tergantung logika JSON.'),

                        Forms\Components\Textarea::make('condition_value')
                            ->label('JSON Condition')
                            ->rows(3)
                            ->helperText('Contoh Min Purchase: {"amount": 100000} | Contoh BOGO: {"product_id": 1, "buy_quantity": 1, "get_quantity": 1}'),
                    ])
                    ->columns(2)
                    ->hidden(fn (Get $get) => $get('applicable_for') === 'sales_order'),

                // -----------------------------------------------------------
                // 5. ACTION (REWARD)
                // -----------------------------------------------------------
                Forms\Components\Section::make('Discount Action')
                    ->schema([
                        Forms\Components\Select::make('discount_type')
                            ->options([
                                'percentage' => 'Percentage (%)',
                                'fixed_amount' => 'Fixed Amount (Rp)'
                            ])
                            ->required()
                            ->default('fixed_amount'),

                        Forms\Components\TextInput::make('discount_value')
                            ->label('Value')
                            ->numeric()
                            ->required(),

                        Forms\Components\TextInput::make('max_discount')
                            ->label('Max Discount (Rp)')
                            ->numeric()
                            ->helperText('Maksimal potongan (khusus tipe persentase).'),

                        Forms\Components\DateTimePicker::make('valid_from'),
                        Forms\Components\DateTimePicker::make('valid_to'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Forms\Components\Toggle::make('is_cumulative')
                            ->label('Cumulative?')
                            ->helperText('Aktifkan jika boleh digabung dengan promo lain. Matikan jika Eksklusif.'),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('applicable_for')
                    ->label('Channel')
                    ->badge()
                    ->colors([
                        'primary' => 'sales_order',
                        'success' => 'pos',
                        'warning' => 'all',
                    ]),
                Tables\Columns\TextColumn::make('valid_from')->dateTime('d M Y')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('valid_to')->dateTime('d M Y')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
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
            'index' => Pages\ListDiscountRules::route('/'),
            'create' => Pages\CreateDiscountRule::route('/create'),
            'edit' => Pages\EditDiscountRule::route('/{record}/edit'),
        ];
    }
}
