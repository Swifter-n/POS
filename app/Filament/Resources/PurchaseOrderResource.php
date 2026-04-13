<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Filament\Resources\PurchaseOrderResource\RelationManagers;
use App\Models\BusinessSetting;
use App\Models\Plant;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shipment;
use App\Models\ShippingRate;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Models\User;
use App\Models\Vendor;
use App\Traits\HasPermissionChecks;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PurchaseOrderResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Inventory Management';

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

    // ==========================================================
    // KONTROL HAK AKSES & DATA
    // ==========================================================

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) {
             return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);

        // Owner bisa lihat semua
        if (self::userHasRole('Owner')) {
            return $query;
        }

        // ==========================================================
        // --- PERBAIKAN: Filter berdasarkan Plant ---
        // ==========================================================
        // Filter berdasarkan Plant jika user adalah Non-Owner
        $userPlantId = null;
        $user->loadMissing('locationable');
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        }

        if ($userPlantId) {
            // Tampilkan PO yang ditujukan ke Plant user
            $query->where('plant_id', $userPlantId);
        } else {
            // Jika user tidak terhubung ke Plant, jangan tampilkan PO
             $query->whereRaw('0 = 1');
        }
        // ==========================================================

        return $query;
    }

    public static function canCreate(): bool
    {
        return self::userHasPermission('create purchase orders');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Purchase Order Header')
                    ->schema([
                        // --- Row 1: Number & Type ---
                        Forms\Components\TextInput::make('po_number')
                            ->default('PO-' . date('Ym') . '-' . random_int(1000, 9999))
                            ->readOnly()
                            ->required()
                            ->columnSpan(1),

                        Forms\Components\Select::make('po_type')
                            ->label('PO Type')
                            ->options([
                                'finished_goods' => 'PO Finished Goods',
                                'raw_material' => 'PO Raw Material',
                                'merchandise' => 'PO Merchandise',
                                'asset' => 'PO Asset',
                                'consignment_purchase' => 'PO Consignment',
                                'consignment_buyout' => 'PO Consignment Buyout',
                            ])
                            ->required()
                            ->live()
                            ->columnSpan(1),

                        Forms\Components\Select::make('price_type')
                            ->options([
                                'standard' => 'Standard Price',
                                'special' => 'Harga Spesial (Promo)',
                                'consignment' => 'Consignment',
                            ])
                            ->default(fn(Get $get) => $get('po_type') === 'consignment_purchase' ? 'consignment' : 'standard')
                            ->required()
                            ->live()
                            ->columnSpan(1),

                        // --- Row 2: Plant & Vendor (Triggers Rate Calculation) ---
                        Forms\Components\Select::make('plant_id')
                            ->label('Destination Plant')
                            ->options(Plant::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->reactive() // Trigger calculation
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateShippingCost($get, $set))
                            ->columnSpan(1),

                        Forms\Components\Select::make('vendor_id')
                            ->label('Supplier')
                            ->relationship('vendor', 'name', fn (Builder $query) => $query->where('type', 'Supplier'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->reactive() // Trigger calculation
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateShippingCost($get, $set))
                            ->columnSpan(1),

                        // --- Row 3: Shipping Logic & Cost ---
                        Forms\Components\Select::make('shipping_method')
                            ->label('Shipping Method')
                            ->options([
                                'supplier_delivery' => 'Delivered by Supplier (Free Shipping)',
                                'self_pickup' => 'Self Pickup (Internal Shipment)',
                            ])
                            ->default('supplier_delivery')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateShippingCost($get, $set))
                            ->helperText(fn (Get $get) => $get('shipping_method') === 'self_pickup'
                                ? 'Cost calculated based on Vendor Area -> Plant Area.'
                                : 'Supplier handles delivery (Free).')
                            ->columnSpan(1),

                        Forms\Components\TextInput::make('shipping_cost')
                            ->label('Est. Shipping Cost')
                            ->prefix('Rp')
                            ->numeric()
                            ->readOnly() // Auto-calculated only
                            ->dehydrated() // Save to DB
                            ->default(0)
                            ->extraAttributes(['class' => 'bg-gray-50 font-medium'])
                            ->helperText('Auto-calculated from Shipping Rates.')
                            ->columnSpan(1),

                        // --- Row 4: Dates ---
                        Forms\Components\DatePicker::make('order_date')
                            ->default(now())
                            ->required(),
                        Forms\Components\DatePicker::make('expected_delivery_date')
                            ->default(now()->addDays(3))
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Financial Summary')
                    ->schema([
                        Forms\Components\TextInput::make('sub_total')
                            ->numeric()->readOnly()->prefix('Rp')
                            ->mask(RawJs::make('$money($input)'))
                            ->dehydrated(),

                        Forms\Components\TextInput::make('tax')
                            ->label('Tax (PPN)')
                            ->numeric()->readOnly()->prefix('Rp')
                            ->mask(RawJs::make('$money($input)'))
                            ->dehydrated(),

                        Forms\Components\TextInput::make('total_amount')
                            ->label('Grand Total')
                            ->numeric()->readOnly()->prefix('Rp')
                            ->mask(RawJs::make('$money($input)'))
                            ->extraAttributes(['class' => 'text-xl font-bold'])
                            ->dehydrated(),

                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
               Tables\Columns\TextColumn::make('po_number')->searchable(),
                Tables\Columns\TextColumn::make('vendor.name')->label('Supplier')->searchable(),
                Tables\Columns\TextColumn::make('plant.name') // Tampilkan nama Plant tujuan
                    ->label('Destination Plant')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('po_type')->badge()
                    ->label('PO Type')
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('total_amount') // <-- Nama kolom DB
                    ->label('Grand Total')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->colors([
                        'gray' => 'draft', 'warning' => 'approved',
                        'info' => 'partially_received', 'success' => 'fully_received',
                        'danger' => 'cancelled',
                        'primary' => 'partially_returned', // Status baru
                        'success' => 'fully_returned', // Status baru
                    ]),
                Tables\Columns\TextColumn::make('order_date')->date()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('purchasing_group')
                ->label('Purchasing Group')
                ->relationship('vendor.purchasingGroup', 'name')
                ->searchable()
                ->preload(),
                Tables\Filters\SelectFilter::make('plant')
                    ->relationship('plant', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')->color('primary')->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update(['status' => 'approved']);
                        Notification::make()->title('Purchase Order Approved')->success()->send();
                    })
                    ->visible(fn ($record) =>
                        $record->status === 'draft' &&
                        self::userHasPermission('approve purchase orders')
                    ),
                    Tables\Actions\Action::make('receive')
        ->label('Receive Items (GR)')
        ->icon('heroicon-o-inbox-arrow-down')
        ->color('success')
        ->url(fn ($record) => GoodsReceiptResource::getUrl('receive', ['po' => $record]))
        ->visible(fn (PurchaseOrder $record) =>
        in_array($record->status, ['approved', 'partially_received']) &&
        $record->po_type !== 'consignment_purchase' &&
        // Pastikan masih ada sisa item yang belum diterima (logic quantity_remaining > 0)
        $record->items->sum('quantity_remaining') > 0
    ),
        // ->visible(fn ($record) =>
        //     in_array($record->status, ['approved', 'partially_received']) &&
        //     $record->shipping_method === 'supplier_delivery' && // Cek Metode
        //     $record->po_type !== 'consignment_purchase' &&
        //     self::userHasPermission('receive goods')
        // ),

    // --- ACTION 2: CREATE SHIPMENT (Pickup) ---
    // Muncul HANYA jika Kita Jemput (self_pickup)
    Tables\Actions\Action::make('createInternalShipment')
        ->label('Create Shipment')
        ->icon('heroicon-o-truck')
        ->color('warning')
        ->visible(fn (PurchaseOrder $record) =>
            in_array($record->status, ['approved', 'partially_received']) &&
            $record->items->sum('quantity_remaining') > 0
        )
        ->form(function (PurchaseOrder $record) {
            // Ambil item yang masih sisa
            $items = $record->items->filter(fn($item) => $item->quantity_remaining > 0)
                ->map(fn($item) => [
                    'po_item_id' => $item->id,
                    'product_name' => $item->product->name,
                    'uom' => $item->uom,
                    'qty_remaining' => $item->quantity_remaining,
                    'qty_to_ship' => $item->quantity_remaining,
                ]);

            return [
                Forms\Components\Section::make('Pickup Details')->schema([
                    Forms\Components\DatePicker::make('scheduled_for')
                        ->label('Tanggal Jemput')
                        ->default(now())
                        ->required(),
                    // Tambahkan field driver/kendaraan jika perlu
                ]),

                Forms\Components\Repeater::make('items')
                    ->label('Barang yang Dijemput')
                    ->schema([
                        Forms\Components\TextInput::make('product_name')->disabled(),
                        Forms\Components\TextInput::make('qty_remaining')
                            ->label('Sisa PO')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\TextInput::make('qty_to_ship')
                            ->label('Qty Angkut')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->maxValue(fn (Get $get) => (int) $get('qty_remaining'))
                            ->live(onBlur: true),

                        // Tampilkan UoM agar user sadar satuannya apa
                        Forms\Components\TextInput::make('uom')
                            ->label('Satuan')
                            ->disabled()
                            ->dehydrated(),

                        Forms\Components\Hidden::make('po_item_id'),
                    ])
                    ->default($items->toArray())
                    ->addable(false)
                    ->deletable(true)
                    ->columns(4) // Sesuaikan kolom
            ];
        })
        ->action(function (PurchaseOrder $record, array $data) {
            if (!isset($data['items']) || empty($data['items'])) return;

            // Hitung estimasi biaya (seperti logic sebelumnya)
            $finalTransportCost = $record->shipping_cost;
            if ($finalTransportCost <= 0 && $record->plant_id && $record->vendor_id) {
                $rate = ShippingRate::where('business_id', $record->business_id)
                    ->where('from_area_id', $record->vendor->area_id)
                    ->where('to_area_id', $record->plant->area_id)
                    ->orderBy('cost', 'asc')
                    ->first();

                if ($rate) {
                    $finalTransportCost = $rate->cost;
                }
            }

            $shipment = null;

            DB::transaction(function () use ($record, $data, $finalTransportCost, &$shipment) {
                // 1. Create Shipment Header
                $shipment = Shipment::create([
                    'shipment_number' => 'SHP-PO-' . date('Ymd') . '-' . rand(1000,9999),
                    'business_id' => $record->business_id,
                    'status' => 'ready_to_ship',
                    'scheduled_for' => $data['scheduled_for'],
                    'source_plant_id' => null,
                    'destination_plant_id' => $record->plant_id,
                    'transport_cost' => $finalTransportCost,
                ]);

                // 2. Link PO
                $shipment->purchaseOrders()->attach($record->id, [
                    'business_id' => $record->business_id
                ]);

                // 3. Create Items dengan KONVERSI UOM
                foreach ($data['items'] as $itemData) {
                    $poItem = PurchaseOrderItem::find($itemData['po_item_id']);

                    if ($poItem) {
                        // A. Ambil UoM dari PO Item
                        $poUomName = $poItem->uom;
                        $inputQty = (float) $itemData['qty_to_ship'];

                        // B. Cari Konversi ke Base UoM
                        $product = $poItem->product;
                        $conversionRate = 1;

                        // Cek apakah UoM PO sama dengan Base UoM Produk?
                        if (strtoupper(trim($poUomName)) !== strtoupper(trim($product->base_uom))) {
                            // Jika beda, cari ratenya di tabel product_uoms
                            $uomData = ProductUom::where('product_id', $product->id)
                                ->where('uom_name', $poUomName)
                                ->first();

                            if ($uomData) {
                                $conversionRate = $uomData->conversion_rate;
                            } else {
                                // Fallback warning jika data master tidak lengkap
                                // (Bisa throw exception jika ingin strict)
                            }
                        }

                        // C. Hitung Qty dalam Base UoM
                        $qtyBase = $inputQty * $conversionRate;

                        // D. Simpan ke Shipment Item
                        $shipment->items()->create([
                            'product_id' => $poItem->product_id,
                            'quantity' => $qtyBase, // SIMPAN BASE QUANTITY
                            // Opsional: Simpan UoM asli jika tabel support, tapi standar shipment biasanya base
                        ]);
                    }
                }

                Notification::make()->title('Internal Shipment Created')->success()->send();
            });

            if ($shipment) {
                return redirect(ShipmentResource::getUrl('edit', ['record' => $shipment]));
            }
        }),
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
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function updateShippingCost(Get $get, Set $set): void
    {
        $method = $get('shipping_method');

        // 1. Jika Supplier Delivery -> Free Shipping
        if ($method === 'supplier_delivery' || empty($method)) {
            $set('shipping_cost', 0);
            return;
        }

        // 2. Jika Self Pickup -> Cari Tarif
        $plantId = $get('plant_id');
        $vendorId = $get('vendor_id');
        $businessId = Auth::user()->business_id;

        if (!$plantId || !$vendorId) {
            $set('shipping_cost', 0);
            return;
        }

        $plant = Plant::find($plantId);
        $vendor = Vendor::find($vendorId);

        if (!$plant?->area_id || !$vendor?->area_id) {
            Notification::make()
                ->title('Missing Area Data')
                ->body('Please ensure both Plant and Vendor have an assigned Area for rate calculation.')
                ->warning()
                ->send();
            $set('shipping_cost', 0);
            return;
        }

        $rate = ShippingRate::where('business_id', $businessId)
            ->where('from_area_id', $vendor->area_id)
            ->where('to_area_id', $plant->area_id)
            ->orderBy('cost', 'asc')
            ->first();

        if ($rate) {
            $set('shipping_cost', $rate->cost);
            Notification::make()->title('Shipping Cost Updated')
                ->body('Rate found: ' . number_format($rate->cost))
                ->success()->send();
        } else {
            $set('shipping_cost', 0);
            Notification::make()->title('No Rate Found')
                ->body('No shipping rate configured for this route. Cost set to 0.')
                ->warning()->send();
        }
    }

}
