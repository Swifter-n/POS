<?php

namespace App\Filament\Resources;

use App\Enums\OrderType;
use App\Filament\Resources\TransactionResource\Pages;
use App\Filament\Resources\TransactionResource\RelationManagers;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Barcode;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Outlet;
use App\Models\PriceListItem;
use App\Models\Promo;
use App\Models\Zone;
use App\Services\DiscountService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

class TransactionResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Orders';
    protected static ?string $navigationGroup = 'Transaction Management';

    private static function userHasPermission(string $permissionName): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if (self::userHasRole('Owner')) return true;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }

    private static function userHasRole(string $roleName): bool
    {
        $user = Auth::user();
        if (!$user) return false;

        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }

 public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        // Owner bisa melihat semua Order POS di dalam bisnisnya
        if ($user->hasRole('Owner')) { // Asumsi Spatie Role
            return parent::getEloquentQuery()
                ->whereHas('outlet', fn(Builder $q) => $q->where('business_id', $user->business_id));
        }

        // Staff Outlet hanya melihat Order POS di outletnya
        if ($user->locationable_type === Outlet::class) {
            return parent::getEloquentQuery()->where('outlet_id', $user->locationable_id);
        }

        return parent::getEloquentQuery()->whereRaw('0 = 1');
    }

    public static function form(Form $form): Form
{
    $taxSetting = BusinessSetting::where('business_id', Auth::user()->business_id)
            ->where('type', 'tax')
            ->where('status', true)
            ->first();
        $defaultTax = $taxSetting ? $taxSetting->value : 0;
    return $form
        ->schema([
            Forms\Components\Wizard::make([
                    Forms\Components\Wizard\Step::make('order-details') // <-- Beri key (nama)
                        ->label('Order Details') // <-- Label untuk tampilan
                        ->icon('heroicon-o-user-circle')
                        ->schema([
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('order_number')
                                    ->default('POS-' . random_int(100000, 999999))
                                    ->readOnly()
                                    ->required(),

                            Forms\Components\Select::make('outlet_id')
                                    ->relationship(
                                        name: 'outlet',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: function (Builder $query) {
                                            $user = Auth::user();
                                            if ($user->hasRole('Owner') && $user->business_id) {
                                                // 1. Jika Owner, tampilkan semua outlet di bisnisnya
                                                return $query->where('business_id', $user->business_id);
                                            }
                                            elseif ($user->locationable_type === Outlet::class && $user->locationable_id) {
                                                // 2. Jika bukan Owner TAPI staff, batasi ke outletnya saja
                                                return $query->where('id', $user->locationable_id);
                                            }

                                            return $query->whereRaw('1 = 0');
                                        }
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live(),

                            Forms\Components\TextInput::make('customer_name')
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('customer_phone')
                                ->tel()
                                ->maxLength(255),

                            Forms\Components\Select::make('type_order')
                                ->label('Type Order')
                                ->required()
                                ->options([
                                    'Store' => 'Store',
                                    'Online' => 'Online',
                                ])
                                ->searchable()
                                ->live()
                                ->preload(),

                            Forms\Components\Select::make('table_number')
                                ->options(function (Get $get): array {
                                    $outletId = $get('outlet_id');
                                    if (!$outletId) {
                                        return [];
                                    }

                                    // Ganti query lama dengan query polimorfik
                                    return Barcode::where('barcodeable_type', Outlet::class)
                                        ->where('barcodeable_id', $outletId)
                                        ->where('status', true) // Hanya tampilkan barcode/meja yang aktif
                                        ->pluck('code', 'code') // Ambil 'code' (bukan 'table_number')
                                        ->toArray();
                                })
                                ->hidden(fn (Get $get) => $get('type_order') !== 'Store')
                                ->searchable()
                                ->live()
                                ->preload(),

                            Forms\Components\TextInput::make('email_customer')
                                ->email()
                                ->required(fn (Get $get) => $get('type_order') === 'Online')
                                ->maxLength(255),

                            Forms\Components\RichEditor::make('address_customer')
                                ->maxLength(65535)
                                ->hidden(fn (Get $get) => $get('type_order') !== 'Online')
                                ->required(fn (Get $get) => $get('type_order') === 'Online')
                                ->columnSpanFull(),

                            Forms\Components\TextInput::make('city_customer')
                                ->maxLength(255)
                                ->hidden(fn (Get $get) => $get('type_order') !== 'Online')
                                ->required(fn (Get $get) => $get('type_order') === 'Online'),

                            Forms\Components\TextInput::make('post_code_customer')
                                ->maxLength(255)
                                ->hidden(fn (Get $get) => $get('type_order') !== 'Online')
                                ->required(fn (Get $get) => $get('type_order') === 'Online'),
                        ]),
                    ]),

                //=========================================================
                // LANGKAH 2: KERANJANG BELANJA (REPEATER)
                //=========================================================
                Forms\Components\Wizard\Step::make('order-items')
                        ->label('Order Items')
                        ->icon('heroicon-o-list-bullet')
                        ->schema([
                            Forms\Components\Repeater::make('items')
                                ->relationship()
                                ->schema([
                                    Forms\Components\Select::make('product_id')
                                        ->label('Product')
                                        ->options(
                                            Product::where('business_id', Auth::user()->business_id)
                                                ->where('product_type', 'finished_good')
                                                ->pluck('name', 'id')
                                                ->toArray()
                                        )
                                        ->searchable()
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Get $get, Set $set, $livewire, $state) {
                                            if (empty($state)) return;
                                            // Panggil helper di Page (Create/Edit)
                                            $livewire->updateLineItemPrice($get, $set);
                                        })
                                        ->columnSpan(3), // <-- Diubah ke 3

                                    // ==========================================================
                                    // --- FIELD UOM BARU DITAMBAHKAN ---
                                    // ==========================================================
                                    Forms\Components\Select::make('uom')
                                        ->label('Unit')
                                        ->options(function (Get $get): array {
                                            $product = Product::find($get('product_id'));
                                            if (!$product) return [];
                                            // Hanya tampilkan UoM tipe 'selling'
                                            return $product->uoms()
                                                ->where('uom_type', 'selling')
                                                ->pluck('uom_name', 'uom_name')
                                                ->toArray();
                                        })
                                        ->live()
                                        ->required()
                                        ->afterStateUpdated(function (Get $get, Set $set, $livewire) {
                                            // Panggil helper UoM baru di Page
                                            $livewire->updateLineItemFromUom($get, $set);
                                        })
                                        ->columnSpan(2),

                                    Forms\Components\TextInput::make('quantity')
                                        ->numeric()
                                        ->required()
                                        ->default(1)
                                        ->minValue(1)
                                        ->inputMode('numeric') // Tambahkan ini
                                        ->live(onBlur: true) // Ubah ke onBlur untuk performa lebih baik
                                        ->afterStateUpdated(function (Get $get, Set $set, $livewire) {
                                            // Pastikan nilai quantity adalah numeric
                                            $quantity = $get('quantity');
                                            if (!is_numeric($quantity) || $quantity < 1) {
                                                $set('quantity', 1);
                                            }
                                            // Panggil helper standar
                                            $livewire->updateLineItemTotal($get, $set);
                                        })
                                        ->columnSpan(1),

                                    Forms\Components\TextInput::make('price')
                                        ->label('Price / UoM') // <-- Label diubah
                                        ->numeric()->readOnly()->prefix('Rp')
                                        ->columnSpan(2),

                                    Forms\Components\TextInput::make('total')
                                        ->numeric()->readOnly()->prefix('Rp')
                                        ->columnSpan(2),

                                    Forms\Components\Textarea::make('note')->label('Note')->rows(2)->columnSpanFull(),
                                ])
                                ->columns(10)
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, $livewire) {
                                    $livewire->updateTotals($get, $set);
                                })
                                ->deleteAction(
                                    fn (Forms\Components\Actions\Action $action) => $action->after(
                                        fn (Get $get, Set $set, $livewire) => $livewire->updateTotals($get, $set)
                                    )
                                )
                                ->addActionLabel('Add Item')
                                ->defaultItems(0)
                                ->collapsible()
                                ->cloneable(),
                        ]),

                    //=========================================================
                    // LANGKAH 3: PEMBAYARAN & TOTAL
                    // (PERBAIKAN: Teruskan Get dan Set)
                    //=========================================================
                    Forms\Components\Wizard\Step::make('payment') // <-- Beri key (nama)
                        ->label('Payment')
                        ->icon('heroicon-o-credit-card')
                        ->schema([
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('sub_total')
                                    ->numeric()->readOnly()->prefix('Rp')->default(0),
                                    // ->dehydrated(false) <-- DIHAPUS

                                Forms\Components\TextInput::make('tax')
                                    ->numeric()->readOnly()->prefix('Rp')->default(0)
                                    // ->dehydrated(false) <-- DIHAPUS
                                    ->helperText(fn() => "Tax Rate: " . ($defaultTax) . "%"),

                                Forms\Components\TextInput::make('promo_code_input')
                                    ->label('Promo Code')
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, $livewire) { // <-- $get, $set
                                        $livewire->updateTotals($get, $set);
                                    })
                                    ->placeholder('Enter promo code'),

                                Forms\Components\TextInput::make('discount')
                                    ->numeric()->readOnly()->prefix('Rp')->default(0),

                                Forms\Components\Placeholder::make('applied_discounts_display')
                                    ->label('Applied Discounts')
                                    ->content(function (Get $get): HtmlString { // <-- 1. Tambahkan type-hint HtmlString
                                        // 2. Ambil string mentah dari $this->data
                                        $rawHtml = $get('applied_discounts_display') ?? '';

                                        // 3. Bungkus string mentah di dalam objek HtmlString
                                        return new HtmlString($rawHtml);
                                    })
                                    // ->html() // <-- 4. HAPUS baris ini
                                    ->dehydrated(false)
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('total_items')
                                    ->numeric()->readOnly()->suffix('Items')->default(0),
                                    // ->dehydrated(false) <-- DIHAPUS

                                Forms\Components\TextInput::make('total_price')
                                    ->label('Grand Total')
                                    ->numeric()->readOnly()->prefix('Rp')->default(0)
                                    // ->dehydrated(false) <-- DIHAPUS
                                    ->extraAttributes(['class' => 'font-bold text-lg']),

                                Forms\Components\Select::make('payment_method')
                                    ->options(['cash' => 'Cash', 'card' => 'Debit/Credit Card', 'qris' => 'QRIS', 'transfer' => 'Bank Transfer'])
                                    ->required()->default('cash')->live(),
                                Forms\Components\FileUpload::make('proof')
                                    ->image()->maxSize(2048)->directory('payment-proofs')
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get) => in_array($get('payment_method'), ['card', 'qris', 'transfer'])),
                            ]),
                        ])
                ])
                ->columnSpanFull()
                ->skippable()
                 ->persistStepInQueryString('step')

                // ==========================================================
                // --- INI ADALAH SOLUSI YANG BENAR ---
                // ==========================================================
                // Beri tahu Wizard untuk merender tombol 'Create' sendiri
                // (menggunakan Action standar dari Halaman CreateRecord)
                ->submitAction(new HtmlString(
                    // Kita gunakan 'wire:click' untuk memanggil 'create'
                    // dan 'type="button"' untuk MENCEGAH 'Enter'
                    '<button
        type="button"
        wire:click="create"
        class="fi-btn fi-btn-size-md fi-btn-color-primary font-semibold rounded-lg shadow-sm
               transition-all duration-200 hover:shadow-md hover:translate-y-[-1px]
               focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
    >
        <x-filament::icon icon="heroicon-o-plus-circle" class="w-5 h-5 mr-2" />
        Create
    </button>'
                ))
            ]);
    }



    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->searchable(),
                Tables\Columns\TextColumn::make('type_order')->label('Type Order')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('customer_name')->label('Customer Name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('payment_method')->label('Payment Method')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('total_price')->label('Total Price')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->sortable()->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Payment Status')->badge()
                    ->colors([
                        'success' => fn($state): bool => in_array($state, ['success', 'paid', 'settled']),
                        'warning' => fn($state): bool => in_array($state, ['pending', 'unpaid']),
                        'danger' => fn($state): bool => in_array($state, ['failed', 'Expired', 'cancelled']),
                    ]),
                Tables\Columns\TextColumn::make('created_at')->label('Order Date')->dateTime('d M Y H:i')->sortable()->toggleable()->wrap(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('payAndConsume')
                    ->label('Approve Payment') // Ganti nama
                    ->color('success')->icon('heroicon-o-check-circle')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Payment')
                    ->modalDescription('Are you sure you want to mark this bill as paid? Stock will be consumed.')
                    // Tampilkan jika status 'Pending'
                    ->visible(fn (Order $record) => in_array(strtolower($record->status), ['pending', 'open', 'unpaid']))
                    ->action(function (Order $record) {
                        try {
                            $record->update(['status' => 'paid']);

                            Notification::make()->title('Bill has been paid successfully!')->body('Stock consumption is being processed.')->success()->send();

                        } catch (\Exception $e) {
                            Log::error("PayBill Error: " . $e->getMessage());
                            Notification::make()->title('Payment Failed')->body($e->getMessage())->danger()->send();
                            $this->halt();
                        }
                    }),
                Tables\Actions\Action::make('cancelOrder')
                    ->label('Cancel Order')
                    ->color('danger')->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    // Tampilkan jika status BISA dibatalkan
                    // Yaitu: 'pending' (belum bayar) ATAU 'paid' (sudah bayar, perlu return stok)
                    ->visible(fn (Order $record) =>
                        in_array(strtolower($record->status), ['pending', 'open', 'unpaid', 'paid']) &&
                        self::userHasPermission('create pos orders') // Ganti dgn permission yg sesuai
                    )
                    ->action(function (Order $record) {
                        // Cukup update status. Observer (yang sudah kita buat)
                        // akan 'mendengar' ini dan memicu event PosOrderCancelled
                        // HANYA JIKA status sebelumnya adalah 'paid'.
                        $record->update(['status' => 'cancelled']);

                        Notification::make()->title('Order has been cancelled.')->warning()->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Order $record) => in_array(strtolower($record->status), ['pending', 'open', 'unpaid'])),
                Tables\Actions\ViewAction::make()
                    ->label('View Details')
                    ->icon('heroicon-o-eye'),
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
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
            'view' => Pages\ViewTransaction::route('/{record}'),
        ];
    }
}
