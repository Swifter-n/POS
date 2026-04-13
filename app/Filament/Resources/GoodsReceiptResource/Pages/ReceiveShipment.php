<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\GoodsReceipt;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\PutawayStrategyService;
use App\Traits\HasPermissionChecks;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
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
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReceiveShipment extends Page implements HasForms
{
    use InteractsWithForms, HasPermissionChecks;
    protected static string $resource = GoodsReceiptResource::class;
    protected static string $view = 'filament.resources.goods-receipt-resource.pages.receive-shipment';

    public ?Shipment $shipment = null;
    public ?array $data = [];

    public bool $isOutletTransfer = false;
    public ?int $destinationPlantId = null;

    // ==========================================================
    // PERMISSION HELPERS
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
    // MOUNT
    // ==========================================================
    public function mount(Shipment $shipment): void
    {
        abort_unless(
            $this->check(Auth::user(), 'receive shipped items'),
            403,
            'You do not have permission to receive shipped items.'
        );

        // Load relasi
        $shipment->loadMissing('destinationPlant', 'destinationOutlet.supplyingPlant', 'sourcePlant', 'purchaseOrders');
        $this->shipment = $shipment;

        $this->isOutletTransfer = $this->shipment->destination_outlet_id !== null;

        $defaultWarehouseId = null;
        $defaultReceiveLocationId = null;
        $destinationName = 'N/A';
        $destinationPlantName = 'N/A';
        $sourcePlantName = $this->shipment->sourcePlant?->name ?? 'Vendor / External';

        // Jika sumber dari PO, tampilkan Nama Vendor
        if ($this->shipment->purchaseOrders->isNotEmpty()) {
            $sourcePlantName = $this->shipment->purchaseOrders->first()->vendor->name . " (Vendor)";
        }

        $rcvZoneCode = null;
        $locatableType = null;
        $locatableId = null;

        if ($this->isOutletTransfer) {
            // --- Alur STO ke Outlet ---
            $outlet = $this->shipment->destinationOutlet;
            if (!$outlet) { return; }

            $destinationName = $outlet->name . " (Outlet)";
            $destinationPlantName = $outlet->supplyingPlant?->name ?? 'N/A';
            $this->destinationPlantId = $outlet->supplying_plant_id;

            $rcvZoneCode = 'MAIN';
            $locatableType = Outlet::class;
            $locatableId = $outlet->id;

        } else {
            // --- Alur STO/PO ke Plant ---
            $plant = $this->shipment->destinationPlant;
            if (!$plant) { return; }

            $destinationName = $plant->name . " (Plant)";
            $destinationPlantName = $plant->name;
            $this->destinationPlantId = $plant->id;

            $warehouses = Warehouse::where('plant_id', $this->destinationPlantId)->where('status', true)->get();
            if ($warehouses->count() === 1) {
                $defaultWarehouseId = $warehouses->first()->id;
                $rcvZoneCode = 'RCV';
                $locatableType = Warehouse::class;
                $locatableId = $defaultWarehouseId;
            }
        }

        // Cari lokasi default (RCV/MAIN)
        if ($rcvZoneCode && $locatableId) {
            $rcvZone = Zone::where('code', $rcvZoneCode)->first();
            if ($rcvZone) {
                $rcvLocations = Location::where('locatable_type', $locatableType)
                    ->where('locatable_id', $locatableId)
                    ->where('zone_id', $rcvZone->id)
                    ->where('status', true)
                    ->get();

                $defaultLoc = $rcvLocations->firstWhere('is_default_receiving', true);
                if ($defaultLoc) {
                    $defaultReceiveLocationId = $defaultLoc->id;
                } elseif ($rcvLocations->count() === 1) {
                    $defaultReceiveLocationId = $rcvLocations->first()->id;
                }
            }
        }

        $itemsData = $this->shipment->items()
            ->with(['product' => fn($q) => $q->select('id', 'name', 'base_uom')])
            ->get()
            ->map(function ($item) {
                return [
                    'shipment_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'quantity_shipped' => $item->quantity,
                    'base_uom' => $item->product?->base_uom ?? 'PCS',
                    // Default terima sesuai yang dikirim
                    'quantity_received' => $item->quantity,
                    'uom' => $item->product?->base_uom ?? 'PCS',
                    'batch' => $item->batch, // <-- Ambil dari Shipment Item
                    'sled' => $item->sled,   // <-- Ambil dari Shipment Item
                ];
            })
            ->toArray();

        $this->form->fill([
            'shipment_number' => $this->shipment->shipment_number,
            'shipment_id' => $this->shipment->id,
            'source_plant_name' => $sourcePlantName,
            'destination_name' => $destinationName,
            'plant_name' => $destinationPlantName,
            'receipt_date' => now(),
            'warehouse_id' => $defaultWarehouseId,
            'receiving_location_id' => $defaultReceiveLocationId,
            'items' => $itemsData,
        ]);
    }

    // ==========================================================
    // FORM SCHEMA
    // ==========================================================
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('shipment_number')->label('From Shipment (DO)')->readOnly(),

                Placeholder::make('source_plant_name')
                    ->label('Source (Sender)')
                    ->content(fn(Get $get) => $get('source_plant_name') ?? 'N/A'),

                Placeholder::make('destination_name')
                    ->label('Destination')
                    ->content(fn(Get $get) => $get('destination_name') ?? 'N/A'),

                Placeholder::make('plant_name')
                    ->label('Plant Context')
                    ->content(fn(Get $get) => $get('plant_name') ?? 'N/A')
                    ->helperText('Plant tujuan stok.'),

                Select::make('warehouse_id')
                    ->label('Receiving Warehouse')
                    ->options(function (): array {
                        if (!$this->destinationPlantId) return [];
                        return Warehouse::where('plant_id', $this->destinationPlantId)
                            ->where('status', true)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->searchable()->preload()->live()
                    ->required(fn () => !$this->isOutletTransfer)
                    ->visible(fn () => !$this->isOutletTransfer),

                Select::make('receiving_location_id')
                    ->label('Receiving Location')
                    ->options(function (Get $get): array {
                        if ($this->isOutletTransfer) {
                            // --- Alur Outlet ---
                            $zone = Zone::where('code', 'MAIN')->first();
                            if (!$zone) return [];
                            return Location::where('locatable_type', Outlet::class)
                                ->where('locatable_id', $this->shipment->destination_outlet_id)
                                ->where('zone_id', $zone->id)
                                ->where('status', true)
                                ->pluck('name', 'id')
                                ->toArray();
                        } else {
                            // --- Alur Plant/Warehouse ---
                            $selectedWarehouseId = $get('warehouse_id');
                            if (!$selectedWarehouseId) return [];
                            $zone = Zone::where('code', 'RCV')->first();
                            if (!$zone) return [];
                            return Location::where('locatable_type', Warehouse::class)
                                ->where('locatable_id', $selectedWarehouseId)
                                ->where('zone_id', $zone->id)
                                ->where('status', true)
                                ->pluck('name', 'id')
                                ->toArray();
                        }
                    })
                    ->searchable()
                    ->required()
                    ->helperText('Select the specific ACTIVE receiving area (RCV for Warehouse, MAIN for Outlet).'),

                DatePicker::make('receipt_date')->required(),
                Textarea::make('notes')->label('Receipt Notes')->columnSpanFull(),

                Repeater::make('items')
                    ->label('Received Items')
                    ->schema([
                        Placeholder::make('product_name')
                            ->content(fn(Get $get) => $get('product_name'))
                            ->columnSpanFull(),

                        Grid::make(3)->schema([
                            TextInput::make('quantity_shipped')
                                ->numeric()
                                ->readOnly()
                                ->label('Qty Shipped (From DO)'),

                            // --- VALIDASI MAX QUANTITY (Sesuai Request) ---
                            TextInput::make('quantity_received')
                                ->numeric()
                                ->required()
                                ->minValue(0)
                                ->default(fn(Get $get) => $get('quantity_shipped'))
                                // Validasi: Tidak boleh lebih dari yang dikirim
                                ->maxValue(fn (Get $get) => (float) $get('quantity_shipped'))
                                ->live(onBlur: true)
                                ->validationAttribute('Qty Received'),

                            Select::make('uom')->label('UoM')
                                ->options(function (Get $get): array {
                                    $product = Product::find($get('product_id'));
                                    if (!$product) return [];
                                    return $product->uoms()->pluck('uom_name', 'uom_name')->toArray();
                                })
                                ->required(),
                        ])->columnSpanFull(),

                        Grid::make(2)->schema([
                            TextInput::make('batch')
                                ->label('Batch/Lot')
                                ->required(fn (string $operation) => $operation === 'save')
                                ->placeholder('Scan or input batch'),

                            DatePicker::make('sled')
                                ->label('Exp. Date')
                                ->required()
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
                        ])->columnSpanFull(),

                        Hidden::make('product_id'),
                        Hidden::make('base_uom'),
                    ])
                    ->columns(2)
                    ->addable(false)
                    ->deletable(false),
            ])
            ->statePath('data');
    }

    // ==========================================================
    // ACTIONS
    // ==========================================================
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Receive & Finish')
                ->submit('save')
                ->disabled(!$this->check(Auth::user(), 'receive shipped items')),
        ];
    }

    // ==========================================================
    // SAVE LOGIC
    // ==========================================================
    public function save(): void
    {
        if (!$this->check(Auth::user(), 'receive shipped items')) {
            Notification::make()->title('Permission Denied')->danger()->send();
            return;
        }

        $data = $this->form->getState();

        // 1. Validasi Lokasi
        $selectedReceivingLocationId = $data['receiving_location_id'];
        $rcvLocation = Location::find($selectedReceivingLocationId);
        $selectedWarehouseId = null;
        $actualPlantId = null;

        if ($this->isOutletTransfer) {
            if (!$rcvLocation || $rcvLocation->locatable_type !== Outlet::class || $rcvLocation->locatable_id != $this->shipment->destination_outlet_id) {
                Notification::make()->title('Validation Error')->body("Selected Receiving Location is invalid.")->danger()->send();
                $this->halt(); return;
            }
            $actualPlantId = $this->shipment->destinationOutlet?->supplying_plant_id;
            if (!$actualPlantId) {
                 Notification::make()->title('Data Error')->body("Destination Outlet has no Supplying Plant.")->danger()->send();
                 $this->halt(); return;
            }
            // Hack: Ambil warehouse pertama dari plant outlet
            $selectedWarehouseId = Warehouse::where('plant_id', $actualPlantId)->value('id');
            if (!$selectedWarehouseId) {
                 Notification::make()->title('Data Error')->body("Supplying Plant has no Warehouse.")->danger()->send();
                 $this->halt(); return;
            }

        } else {
            // Alur Warehouse
            $selectedWarehouseId = $data['warehouse_id'];
            if (!$rcvLocation || $rcvLocation->locatable_type !== Warehouse::class || $rcvLocation->locatable_id != $selectedWarehouseId) {
                Notification::make()->title('Validation Error')->body("Selected Receiving Location invalid for selected Warehouse.")->danger()->send();
                $this->halt(); return;
            }
            $actualPlantId = $this->destinationPlantId;
        }

        try {
            DB::transaction(function () use ($data, $selectedWarehouseId, $rcvLocation, $actualPlantId) {

                // A. Buat Header GR
                $receipt = GoodsReceipt::create([
                    'receipt_number' => 'GRN-' . date('Ym') . '-' . random_int(1000, 9999),
                    'shipment_id' => $this->shipment->id,
                    'business_id' => $this->shipment->business_id,
                    'warehouse_id' => $selectedWarehouseId,
                    'received_by_user_id' => Auth::id(),
                    'receipt_date' => $data['receipt_date'],
                    'notes' => $data['notes'],
                    'status' => 'received',
                ]);

                $putAwayItems = [];
                $hasReceivedItems = false;
                $targetLocationId = $rcvLocation->id;

                // Load Purchase Orders terkait (jika ada)
                $linkedPos = $this->shipment->purchaseOrders;
                $userId = Auth::id();

                // B. Loop Items
                foreach ($data['items'] as $itemData) {
                    $qtyReceived = (float)($itemData['quantity_received'] ?? 0);

                    if ($qtyReceived <= 0) continue;

                    // Validasi lagi di backend (Fail-safe)
                    if ($qtyReceived > (float)$itemData['quantity_shipped']) {
                        throw new \Exception("Item received exceeds quantity shipped.");
                    }

                    $hasReceivedItems = true;
                    $productId = $itemData['product_id'];

                    // Logic UoM Conversion
                    $product = Product::find($productId);
                    if (!$product) continue;
                    $product->loadMissing('uoms');

                    $receivedUomName = $itemData['uom'];
                    $uomData = $product->uoms->where('uom_name', $receivedUomName)->first();
                    $conversionRate = $uomData?->conversion_rate ?? 1;
                    $quantityInBaseUom = $qtyReceived * $conversionRate;

                    // 1. Simpan Item GR
                    $receipt->items()->create([
                        'product_id' => $productId,
                        'quantity_received' => $qtyReceived,
                        'uom' => $receivedUomName,
                        'batch' => $itemData['batch'],
                        'sled' => $itemData['sled'],
                    ]);

                    // 2. Update Inventory
                    $inventory = Inventory::firstOrCreate(
                        ['location_id' => $targetLocationId, 'product_id' => $productId, 'batch' => $itemData['batch']],
                        ['business_id' => $this->shipment->business_id, 'sled' => $itemData['sled'], 'avail_stock' => 0]
                    );
                    $inventory->increment('avail_stock', $quantityInBaseUom);

                    // 3. Log Movement
                    InventoryMovement::create([
                        'inventory_id' => $inventory->id,
                        'quantity_change' => $quantityInBaseUom,
                        'stock_after_move' => $inventory->avail_stock,
                        'type' => 'TRANSFER_IN',
                        'reference_type' => GoodsReceipt::class,
                        'reference_id' => $receipt->id,
                        'user_id' => Auth::id(),
                        'notes' => "Received {$qtyReceived} {$receivedUomName} from Shipment #{$this->shipment->shipment_number}",
                    ]);

                    // 4. Collect Data PutAway
                    $putAwayItems[] = [
                        'product_id' => $productId,
                        'quantity' => $qtyReceived,
                        'uom' => $receivedUomName,
                    ];

                    // 5. UPDATE PURCHASE ORDER (Logic Penting!)
                    if ($linkedPos->isNotEmpty()) {
                        foreach ($linkedPos as $po) {
                            $poItem = $po->items()->where('product_id', $productId)->first();
                            if ($poItem) {
                                // Asumsi UoM PO = UoM Receive, jika tidak harus dikonversi
                                $poItem->increment('quantity_received', $qtyReceived);
                            }
                        }
                    }
                } // End Loop Items

                if (!$hasReceivedItems) {
                    throw new \Exception("No items were received.");
                }

                // C. Update Status Shipment -> Delivered
                $this->shipment->update(['status' => 'delivered', 'delivered_at' => now()]);

                // Update Fleet Status
                $this->shipment->load('fleets');
                if ($this->shipment->fleets->isNotEmpty()) {
                    $this->shipment->fleets()->update(['status' => 'available']);
                    if (method_exists($this->shipment->fleets(), 'syncWithPivotValues')) {
                        $this->shipment->fleets()->syncWithPivotValues(
                            $this->shipment->fleets->pluck('id')->all(),
                            ['status' => 'completed']
                        );
                    }
                }

                // D. Update Status PO Global
                if ($linkedPos->isNotEmpty()) {
                    foreach ($linkedPos as $po) {
                        $po->refresh();
                        $allFulfilled = $po->items->every(fn($i) => $i->quantity_received >= $i->quantity);
                        $po->update(['status' => $allFulfilled ? 'fully_received' : 'partially_received']);
                    }
                }

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

                            // SIMPAN HASIL AI KE SINI
                            'suggested_location_id' => $suggestedLocationId,
                        ]);
                    }

                    Log::info("Putaway Task {$putAwayTask->transfer_number} created with intelligent suggestions.");
                }

            }); // End Transaction

            Notification::make()->title('Shipment Received Successfully')->success()->send();
            $this->redirect(ShipmentResource::getUrl('index'));

        } catch (\Exception $e) {
            Log::error("ReceiveShipment Error: " . $e->getMessage());
            Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
            $this->halt();
        }
    }
}
