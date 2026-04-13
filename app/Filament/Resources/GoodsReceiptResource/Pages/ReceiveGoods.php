<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\GoodsReceipt;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\PutawayStrategyService;
use App\Traits\HasPermissionChecks;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReceiveGoods extends Page implements HasForms
{
    use InteractsWithForms, HasPermissionChecks;
    protected static string $resource = GoodsReceiptResource::class;
    protected static string $view = 'filament.resources.goods-receipt-resource.pages.receive-goods';

    public ?PurchaseOrder $po;
    public ?array $data = [];
    public ?Location $receivingLocation = null;
    public bool $isConsignment = false;

    // ==========================================================
    // HELPERS (ANTI-CACHE, DIRECT QUERY)
    // ==========================================================

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
    // ACCESS CONTROL & DATA
    // ==========================================================

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (self::userHasRole('Owner')) {
            return parent::getEloquentQuery()->where('business_id', $user->business_id);
        }
        if ($user->locationable_type === Warehouse::class) {
            return parent::getEloquentQuery()->where('warehouse_id', $user->locationable_id);
        }
        return parent::getEloquentQuery()->whereRaw('0 = 1');
    }

    public function mount(PurchaseOrder $po): void
    {
        abort_unless(
            $this->check(Auth::user(), 'receive goods'),
            403, 'You do not have permission to receive goods.'
        );

        $po->loadMissing('plant', 'vendor');
        $this->po = $po;

        if (!$this->po->plant) {
             Notification::make()->title('Error!')
                ->body("Purchase Order #{$this->po->po_number} is not linked to a Plant.")
                ->danger()->persistent()->send();
            redirect(PurchaseOrderResource::getUrl('index'));
            return;
        }

        $this->isConsignment = $this->po->price_type === 'consignment';
        if ($this->isConsignment && !$this->po->vendor_id) {
             Notification::make()->title('Setup Error!')
                ->body("Consignment PO #{$this->po->po_number} must have a Supplier assigned.")
                ->danger()->persistent()->send();
            redirect(PurchaseOrderResource::getUrl('edit', ['record' => $this->po]));
            return;
        }

        // Prepare initial item data
        $itemsData = $this->po->items()->with(['product' => fn($q) => $q->select('id', 'name', 'base_uom')])->get()->map(function ($item) {
            return [
                'purchase_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                // Store product name in DB call later, but useful here for visual reference if needed
                // Note: Placeholder in repeater handles visual display
                'quantity_ordered' => $item->quantity,
                'po_uom' => $item->uom,
                // Show remaining quantity
                'quantity_remaining' => max(0, $item->quantity - $item->quantity_received),
                'quantity_received' => max(0, $item->quantity - $item->quantity_received), // Default receive remaining
                'uom' => $item->uom,
                'sled' => now()->addYear(),
                'price_per_item' => $item->price_per_item,
                'base_uom' => $item->product?->base_uom ?? 'PCS',
            ];
        })->toArray();

        $this->form->fill([
            'po_number' => $this->po->po_number,
            'purchase_order_id' => $this->po->id,
            'plant_name' => $this->po->plant->name,
            'receipt_date' => now(),
            'warehouse_id' => null,
            'receiving_location_id' => null,
            'items' => $itemsData,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('po_number')->label('From PO Number')->readOnly(),
                Placeholder::make('plant_name')->label('Destination Plant')->content($this->po?->plant?->name ?? 'N/A'),

                Select::make('warehouse_id')
                    ->label('Receiving Warehouse')
                    ->options(fn(): array =>
                        $this->po?->plant?->warehouses()->where('status', true)->pluck('name', 'id')->toArray() ?? []
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText('Select the specific ACTIVE warehouse receiving these items.'),

                // Field Receiving Location (Non-Consignment)
                Select::make('receiving_location_id')
                    ->label('Receiving Location')
                    ->options(function (Get $get): array {
                        $selectedWarehouseId = $get('warehouse_id');
                        if (!$selectedWarehouseId) return [];

                        $rcvZone = Zone::where('code', 'RCV')->first();
                        if (!$rcvZone) return ['error' => 'RCV Zone not found!'];

                        return Location::where('locatable_type', Warehouse::class)
                                     ->where('locatable_id', $selectedWarehouseId)
                                     ->where('zone_id', $rcvZone->id)
                                     ->where('status', true)
                                     ->pluck('name', 'id')
                                     ->toArray();
                    })
                    ->searchable()
                    ->required(fn() => !$this->isConsignment)
                    ->visible(fn() => !$this->isConsignment)
                    ->live()
                    ->helperText('Select the specific ACTIVE receiving area.'),

                DatePicker::make('receipt_date')->required(),
                Textarea::make('notes')->label('Receipt Notes')->columnSpanFull(),

                Repeater::make('items')
                    ->label('Received Items')
                    ->schema(function (Get $get): array {
                        $selectedWarehouseId = $get('../../warehouse_id');

                        // Prepare Consignment Location Options
                        $consignmentLocationOptions = [];
                        if ($this->isConsignment && $selectedWarehouseId) {
                            $consignmentZone = Zone::where('code', 'Z-CON')->first();
                            if ($consignmentZone) {
                                $consignmentLocationOptions = Location::where('ownership_type', 'consignment')
                                    ->where('supplier_id', $this->po->vendor_id)
                                    ->where('zone_id', $consignmentZone->id)
                                    ->where('locatable_type', Warehouse::class)
                                    ->where('locatable_id', $selectedWarehouseId)
                                    ->where('status', true)
                                    ->pluck('name', 'id')
                                    ->toArray();
                            }
                        }

                        return [
                            Placeholder::make('product_name')
                                ->content(fn (Get $get) => Product::find($get('product_id'))?->name)
                                ->columnSpanFull(),

                            TextInput::make('quantity_ordered')
                                ->label('Total Ordered')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(false),

                            // Replaced content() with logic in mount/default
                            TextInput::make('quantity_remaining')
                                ->label('Remaining Qty')
                                ->numeric()
                                ->disabled()
                                ->dehydrated(false),

                    TextInput::make('quantity_received')
                    ->label('Qty Terima Sekarang')
                    ->numeric()
                    ->default(fn(Get $get) => $get('quantity_remaining')) // Default isi sisa
                    ->required()
                    ->minValue(1)
                    // --- TAMBAHAN VALIDASI DI SINI ---
                    ->maxValue(function (Get $get) {
                        // Ambil nilai dari field 'quantity_remaining' di baris yang sama
                        return (int) $get('quantity_remaining');
                    })
                    ->validationAttribute('Qty Terima') // Nama field di pesan error
                    ->live(onBlur: true),

                            Select::make('uom')
                                ->label('UoM')
                                ->options(function (Get $get): array {
                                    $product = Product::find($get('product_id'));
                                    if (!$product) return [];
                                    return $product->uoms()
                                        ->where('uom_type', 'purchasing')
                                        ->pluck('uom_name', 'uom_name')->toArray();
                                })
                                ->required(),

                            TextInput::make('batch')
                                ->label('Batch Number')
                                ->placeholder('Ketik Manual (cth: BATCH-001)')
                                ->required()
                                ->maxLength(255),

                            DatePicker::make('manufacturing_date')
                                ->label('Mfg. Date')
                                ->required()
                                ->maxDate(now()),

                            DatePicker::make('sled')
                                ->label('Exp. Date')
                                ->required()
                                ->minDate(now())
                                ->rule(function (Get $get) {
                                    return function (string $attribute, $value, \Closure $fail) use ($get) {
                                        $product = Product::find($get('product_id'));
                                        if ($product && $product->min_sled_days > 0) {
                                            $minDate = now()->addDays($product->min_sled_days);
                                            if (Carbon::parse($value)->lt($minDate)) {
                                                $fail("SLED kurang dari syarat minimum produk ({$product->min_sled_days} hari).");
                                            }
                                        }
                                    };
                                }),

                            // Consignment Location Logic (Visible only if Consignment)
                            Select::make('location_id')
                                ->label('Consignment Location')
                                ->options($consignmentLocationOptions)
                                ->searchable()
                                ->required($this->isConsignment)
                                ->visible($this->isConsignment),

                            // Hidden fields to pass IDs
                            Hidden::make('purchase_order_item_id'),
                            Hidden::make('product_id'),
                       ];
                    })
                    ->columns($this->isConsignment ? 3 : 3)
                    ->addable(false)
                    ->deletable(false),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        $label = $this->isConsignment ? 'Receive Consignment Stock' : 'Receive Items & Create Put-Away Task';
        return [
            Action::make('save')->label($label)->submit('save')
                ->disabled(!$this->checkPermission(Auth::user(), 'receive goods')),
        ];
    }

    public function save(): void
    {
        // 1. Permission Check
        if (!$this->check(Auth::user(), 'receive goods')) {
            Notification::make()->title('Permission Denied')->danger()->send();
            return;
        }

        $data = $this->form->getState();
        $selectedWarehouseId = $data['warehouse_id'];
        $receiptDate = $data['receipt_date'];
        $notes = $data['notes'] ?? null;

        // 2. Validate Header Location (Standard Only)
        $rcvLocationHeader = null;

        if (!$this->isConsignment) {
            $selectedReceivingLocationId = $data['receiving_location_id'] ?? null;

            if (!$selectedReceivingLocationId) {
                Notification::make()->title('Validation Error')->body('Please select the Receiving Location.')->warning()->send();
                $this->halt(); return;
            }

            $rcvLocationHeader = Location::find($selectedReceivingLocationId);
            if (!$rcvLocationHeader || !$rcvLocationHeader->status) {
                Notification::make()->title('Location Error')->body('Selected Receiving Location is invalid or inactive.')->danger()->send();
                $this->halt(); return;
            }
        }
        // Consignment location validation happens inside the loop

        // 3. Validate OVER-DELIVERY
        foreach ($data['items'] as $itemData) {
            $poItem = PurchaseOrderItem::find($itemData['purchase_order_item_id']);
            if ($poItem) {
                $qtyInput = (float) $itemData['quantity_received'];
                $qtyRemaining = $poItem->quantity - $poItem->quantity_received;

                // Floating point tolerance can be added if needed, but direct comparison usually works for simple integers/decimals
                if ($qtyInput > $qtyRemaining) {
                    // Fetch product name safely
                    $productName = $poItem->product?->name ?? 'Unknown Item';

                    Notification::make()
                        ->title('Over Delivery Detected')
                        ->body("Item '{$productName}' exceeds remaining order. Remaining: {$qtyRemaining}, Input: {$qtyInput}.")
                        ->danger()
                        ->send();
                    $this->halt();
                    return;
                }
            }
        }

        // 4. Database Transaction
        try {
            DB::transaction(function () use ($data, $selectedWarehouseId, $receiptDate, $notes, $rcvLocationHeader) {

                // A. Create GR Header
                $receipt = GoodsReceipt::create([
                    'receipt_number' => 'GRN-' . date('Ym') . '-' . random_int(1000, 9999),
                    'purchase_order_id' => $this->po->id,
                    'business_id' => $this->po->business_id,
                    'warehouse_id' => $selectedWarehouseId,
                    'received_by_user_id' => Auth::id(),
                    'receipt_date' => $receiptDate,
                    'notes' => $notes,
                    'status' => 'received',
                ]);

                $hasReceivedItems = false;
                $userId = Auth::id();

                // B. Process Items
                foreach ($data['items'] as $itemData) {
                    $qtyReceived = (float) ($itemData['quantity_received'] ?? 0);
                    if ($qtyReceived <= 0) continue;

                    $hasReceivedItems = true;
                    $productId = $itemData['product_id'];
                    $uomName = $itemData['uom'];
                    $batchNumber = $itemData['batch'];
                    $sled = $itemData['sled'];

                    // --- LOCATION LOGIC ---
                    $targetLocation = null;

                    if ($this->isConsignment) {
                        // Consignment: Validate per item location
                        $targetLocationId = $itemData['location_id'] ?? null;
                        if ($targetLocationId) {
                             $targetLocation = Location::where('id', $targetLocationId)
                                  ->where('ownership_type', 'consignment')
                                  ->where('supplier_id', $this->po->vendor_id)
                                  ->where('locatable_id', $selectedWarehouseId)
                                  ->where('status', true)
                                  ->first();
                        }

                        if (!$targetLocation) {
                            throw new \Exception("Target consignment location for an item is invalid or inactive.");
                        }
                    } else {
                        // Standard: Use Header Location
                        $targetLocation = $rcvLocationHeader;
                    }

                    // 1. Save GR Item
                    $receipt->items()->create([
                        'product_id' => $productId,
                        'quantity_received' => $qtyReceived,
                        'uom' => $uomName,
                        'batch' => $batchNumber,
                        'sled' => $sled,
                        'manufacturing_date' => $itemData['manufacturing_date'] ?? null,
                    ]);

                    // 2. Base UoM Conversion
                    $product = Product::find($productId);
                    $uomData = $product->uoms->where('uom_name', $uomName)->first();
                    $conversionRate = $uomData?->conversion_rate ?? 1;
                    $qtyBase = $qtyReceived * $conversionRate;

                    // 3. Update Inventory
                    $inventory = Inventory::firstOrCreate(
                        [
                            'location_id' => $targetLocation->id,
                            'product_id'  => $productId,
                            'batch'       => $batchNumber
                        ],
                        [
                            'business_id' => $this->po->business_id,
                            'sled'        => $sled,
                            'avail_stock' => 0
                        ]
                    );

                    $inventory->increment('avail_stock', $qtyBase);

                    // 4. Log Movement
                    InventoryMovement::create([
                        'inventory_id'     => $inventory->id,
                        'quantity_change'  => $qtyBase,
                        'stock_after_move' => $inventory->avail_stock,
                        'type'             => 'GR_PURCHASE',
                        'reference_type'   => GoodsReceipt::class,
                        'reference_id'     => $receipt->id,
                        'user_id'          => $userId,
                        'notes'            => "Received {$qtyReceived} {$uomName} from PO #{$this->po->po_number} into {$targetLocation->name}",
                    ]);

                    // 5. Update PO Item Tracking
                    $poItem = PurchaseOrderItem::find($itemData['purchase_order_item_id']);
                    if ($poItem) {
                        $poItem->increment('quantity_received', $qtyReceived);
                    }
                }

                if (!$hasReceivedItems) {
                    throw new \Exception("No items were received (all quantities were zero). Transaction cancelled.");
                }

                // C. Update Global PO Status
                $this->po->refresh();
                $allFulfilled = $this->po->items->every(function ($item) {
                    return $item->quantity_received >= $item->quantity;
                });
                $this->po->update(['status' => $allFulfilled ? 'fully_received' : 'partially_received']);

                // D. INTELLIGENT PUT-AWAY GENERATION
                // Hanya jalankan jika BUKAN konsinyasi dan ada lokasi RCV (Header)
                // Atau sesuaikan jika Shipment (ambil dari logic Shipment)

                $putawaySourceLocationId = null;
                if (!$this->isConsignment && isset($rcvLocationHeader)) {
                    $putawaySourceLocationId = $rcvLocationHeader->id;
                } elseif (isset($rcvLocation)) { // Untuk ReceiveShipment
                    $putawaySourceLocationId = $rcvLocation->id;
                }

                if ($putawaySourceLocationId) {
                    // 1. Init Service
                    $putawayService = new PutawayStrategyService();

                    // 2. Buat Header Task
                    $transferNumber = 'PA-' . $receipt->receipt_number;
                    $putAwayTask = StockTransfer::create([
                        'transfer_number' => $transferNumber,
                        'business_id' => $this->po->business_id, // atau $this->shipment
                        'source_location_id' => $putawaySourceLocationId,
                        'destination_location_id' => null, // Null, karena tujuan per item beda-beda
                        'status' => 'draft',
                        'transfer_type' => 'put_away',
                        'sourceable_type' => GoodsReceipt::class,
                        'sourceable_id' => $receipt->id,
                        'plant_id' => $this->po->plant_id, // atau $actualPlantId
                        'notes' => "System generated put-away from {$receipt->receipt_number}",
                        'requested_by_user_id' => $userId,
                        'request_date' => now(),
                    ]);

                    // 3. Loop Item untuk Cari Saran Lokasi
                    foreach ($receipt->items as $grItem) {
                        $product = Product::find($grItem->product_id);

                        // --- PANGGIL AI PUTAWAY ---
                        $suggestedBin = $putawayService->findOptimalBin($product, $selectedWarehouseId);
                        if ($suggestedBin) {
                            Log::info("Suggestion Found: " . $suggestedBin->name);
                        } else {
                            Log::warning("No Suggestion Found for Product: " . $product->name . " in Warehouse ID: " . $selectedWarehouseId);
                        }
                        $suggestedLocationId = $suggestedBin ? $suggestedBin->id : null;
                        // --------------------------

                        $putAwayTask->items()->create([
                            'product_id' => $grItem->product_id,
                            'quantity' => $grItem->quantity_received,
                            'uom' => $grItem->uom,
                            'batch' => $grItem->batch,
                            'sled' => $grItem->sled,
                            // SIMPAN HASIL AI KE SINI
                            'suggested_location_id' => $suggestedLocationId,
                        ]);
                    }

                    Log::info("Putaway Task {$putAwayTask->transfer_number} created with intelligent suggestions.");
                }

            }); // End Transaction

            Notification::make()
                ->title('Goods Received Successfully')
                ->body('Stock updated, PO tracking updated' . (!$this->isConsignment ? ', Put-Away task created.' : '.'))
                ->success()
                ->send();

            $this->redirect(PurchaseOrderResource::getUrl('index'));

        } catch (\Exception $e) {
            Log::error("GR Save Error: " . $e->getMessage());

            Notification::make()
                ->title('Transaction Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
