<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Events\ConsignmentStockConsumed;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditPurchaseOrder extends EditRecord
{
    use HasPermissionChecks;
    protected static string $resource = PurchaseOrderResource::class;
    protected $listeners = ['poTotalsUpdated' => 'refreshTotals'];

    public function refreshTotals(): void
    {
        // Reload the record to get fresh calculations from DB
        $this->getRecord()->refresh();

        // Refresh specific fields
        $this->fillForm();
        // OR specifically:
        // $this->refreshFormData(['sub_total', 'total_discount', 'tax', 'total_amount', 'shipping_cost']);

        Log::info("EditPurchaseOrder: Totals refreshed.");
    }

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();
        return [
            /**
             * AKSI 1: APPROVE PURCHASE ORDER
             */
            Actions\Action::make('approve')
                ->label('Approve')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn () => $record->status === 'draft' && $this->check($user, 'approve purchase orders'))
                ->action(function () use ($record) {
                    $record->update(['status' => 'approved']);
                    Notification::make()->title('Purchase Order Approved')->success()->send();
                    $this->refreshFormData(['status']);
                }),

                Actions\Action::make('returnConsignment')
                ->label('Return/Buy Consignment Stock')
                ->color('warning')->icon('heroicon-o-arrow-uturn-left')
                ->requiresConfirmation()
                // Muncul hanya jika PO Konsinyasi sudah Approved (atau status lain)
                ->visible(fn () =>
                    in_array($record->status, ['approved', 'partially_returned', 'fully_received']) && // Izinkan return berkali-kali
                    $record->po_type === 'consignment_purchase' &&
                    $this->check($user, 'approve purchase orders') // Ganti permission jika perlu
                )
                ->form(function (PurchaseOrder $record) { // Terima $record (PO)
                    // 1. Cari Lokasi Konsinyasi sumber
                    $record->loadMissing('vendor', 'plant.warehouses.locations');
                    $vendorId = $record->vendor_id;
                    $plantId = $record->plant_id;
                    $consignmentZone = Zone::where('code', 'Z-CON')->first();

                    if (!$vendorId || !$plantId || !$consignmentZone) {
                         Notification::make()->title('Setup Error')->body('Vendor, Plant, or Consignment Zone (Z-CON) not found.')->danger()->send();
                         return []; // Kembalikan form kosong
                    }

                    // Cari lokasi Z-CON milik vendor di plant ini
                     $sourceConsignmentLocation = Location::where('ownership_type', 'consignment')
                        ->where('supplier_id', $vendorId)
                        ->where('zone_id', $consignmentZone->id)
                        ->where('status', true) // Hanya lokasi aktif
                        ->whereHasMorph('locatable', [Warehouse::class, Outlet::class], fn (Builder $query) => $query->where('plant_id', $plantId))
                        ->first();

                    if (!$sourceConsignmentLocation) {
                         Notification::make()->title('Setup Error')->body("Consignment location (Zone Z-CON) for vendor '{$record->vendor?->name}' not found in plant '{$record->plant?->name}'.")->danger()->send();
                         return [];
                    }

                    // 2. Siapkan data item untuk Repeater
                    $poItemsData = $record->items()->with(['product' => fn($q) => $q->select('id', 'name', 'base_uom')])->get()->map(function ($item) use ($sourceConsignmentLocation) {
                        // Hitung stok tersedia di lokasi konsinyasi (Base UoM)
                        $availableStock = Inventory::where('location_id', $sourceConsignmentLocation->id)
                                          ->where('product_id', $item->product_id)
                                          ->sum('avail_stock');
                        return [
                            'po_item_id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product?->name,
                            'po_quantity' => $item->quantity, // Qty di PO asli (dlm UoM PO)
                            'po_uom' => $item->uom, // UoM di PO asli
                            'available_consignment_stock' => (int) $availableStock, // Stok tersedia (Base UoM)
                            'base_uom' => $item->product?->base_uom ?? 'PCS', // Base UoM
                            'return_uom' => $item->uom, // Default UoM input = UoM PO
                            'return_quantity' => 0, // Default Qty input 0
                        ];
                    })->toArray();


                    // 3. Bangun Skema Form dengan Repeater
                    return [
                        Placeholder::make('info')
                             ->content("Select items and quantity to 'return' (buy) from consignment stock at: " . $sourceConsignmentLocation->name),
                        Repeater::make('items_to_return')
                            ->label('Items to Return/Buy')
                            ->schema([
                                Placeholder::make('product_name_ph') // Nama unik
                                    ->label('Product')
                                    ->content(fn (Get $get) => $get('product_name')),
                                Placeholder::make('available_stock_ph') // Nama unik
                                    ->label('Available Consignment Stock')
                                     ->content(fn (Get $get) => $get('available_consignment_stock') . ' ' . $get('base_uom')),

                                // ==========================================================
                                // --- PERBAIKAN: Tambahkan UoM Conversion ---
                                // ==========================================================
                                Select::make('return_uom')
                                    ->label('Return In (UoM)')
                                    ->options(function (Get $get): array {
                                        // Ambil UoM yang tersedia untuk produk ini
                                        $product = Product::find($get('product_id'));
                                        if (!$product) return [];
                                        $product->loadMissing('uoms'); // Pastikan UoM dimuat
                                        return $product->uoms->pluck('uom_name', 'uom_name')->toArray();
                                    })
                                    ->required()
                                    ->default(fn (Get $get) => $get('po_uom') ?? $get('base_uom')) // Default ke UoM PO
                                    ->helperText('Satuan yang dihitung.'),

                                TextInput::make('return_quantity')
                                    ->label('Qty to Return/Buy')
                                    ->numeric()
                                    ->minValue(0)
                                    // Validasi maxValue dipindahkan ke action()
                                    ->default(0) // Default 0, user harus isi
                                    ->required()
                                    ->helperText('Jumlah dalam satuan di atas.'),
                                // ==========================================================


                                // Hidden fields untuk membawa data penting
                                Hidden::make('po_item_id'),
                                Hidden::make('product_id'),
                                Hidden::make('product_name'), // Bawa nama untuk pesan error
                                Hidden::make('available_consignment_stock'),
                                Hidden::make('base_uom'),
                                Hidden::make('po_uom'), // Bawa UoM PO
                            ])
                            ->columns(4) // Ubah ke 4 kolom
                            ->addable(false)->deletable(false) // Tidak bisa tambah/hapus item dari sini
                            ->default($poItemsData), // Isi repeater dengan data item PO
                    ];
                })
                ->action(function (PurchaseOrder $record, array $data) {
                    try {
                        $returnTransfer = DB::transaction(function () use ($record, $data) { // Ambil hasil returnTransfer
                            $record->loadMissing('vendor', 'plant'); // Muat relasi
                            $userId = Auth::id();
                            $consignmentZone = Zone::where('code', 'Z-CON')->first();

                             if (!$consignmentZone) throw new \Exception('Consignment Zone (Z-CON) not found.');

                             // Cari lokasi Z-CON milik vendor di plant ini
                             $sourceConsignmentLocation = Location::where('ownership_type', 'consignment')
                                ->where('supplier_id', $record->vendor_id)
                                ->where('zone_id', $consignmentZone->id)
                                ->where('status', true) // Pastikan aktif
                                ->whereHasMorph('locatable', [Warehouse::class, Outlet::class], fn (Builder $q) => $q->where('plant_id', $record->plant_id))
                                ->first();

                             if (!$sourceConsignmentLocation) throw new \Exception("Source Consignment location not found.");

                             $itemsReturnedData = []; // Untuk menyimpan item yg akan diretur

                            // 1. Validasi Input & Kumpulkan Data Item
                            foreach ($data['items_to_return'] as $index => $itemData) {
                                // ==========================================================
                                // --- PERBAIKAN: Logika Konversi UoM ---
                                // ==========================================================
                                $inputQty = (float)($itemData['return_quantity'] ?? 0);
                                $inputUomName = $itemData['return_uom'];

                                if ($inputQty > 0) {
                                    $product = Product::find($itemData['product_id']);
                                    if (!$product) continue;
                                    $product->loadMissing('uoms');

                                    // Konversi Qty Input ke Base UoM
                                    $uomData = $product->uoms->where('uom_name', $inputUomName)->first();
                                    if (!$uomData) throw new \Exception("UoM '{$inputUomName}' not found for product '{$itemData['product_name']}'.");
                                    $conversionRate = $uomData->conversion_rate ?? 1;
                                    $qtyToReturnInBase = $inputQty * $conversionRate; // Qty Base UoM
                                    // ==========================================================

                                    // Validasi ulang stok (Base UoM vs Base UoM)
                                    $availableStock = (int) $itemData['available_consignment_stock'];
                                    if (round($qtyToReturnInBase, 5) > round($availableStock, 5)) {
                                        throw \Illuminate\Validation\ValidationException::withMessages([
                                            "items_to_return.{$index}.return_quantity" => "Insufficient stock for '{$itemData['product_name']}'. Available: {$availableStock} (Base UoM), Trying to return: {$qtyToReturnInBase} (Base UoM)"
                                        ]);
                                    }

                                    // Simpan data yg sudah divalidasi
                                    $itemData['quantity_to_return_base'] = $qtyToReturnInBase; // Simpan Qty Base
                                    $itemsReturnedData[] = $itemData;
                                }
                            }

                            if (empty($itemsReturnedData)) {
                                 throw new \Exception("No items selected or quantity is zero.");
                            }

                            // 2. Buat Stock Transfer baru tipe 'RETURN_CONS'
                            $returnTransfer = StockTransfer::create([
                                'transfer_number' => 'RTN-CON-' . date('Ym') . '-' . random_int(1000, 9999),
                                'business_id' => $record->business_id,
                                'plant_id' => $record->plant_id, // Simpan Plant ID
                                'source_location_id' => $sourceConsignmentLocation->id,
                                'destination_location_id' => null, // Tujuan virtual
                                'status' => 'completed', // Langsung completed
                                'request_date' => now(),
                                'requested_by_user_id' => $userId,
                                'notes' => "Consignment return/buyout based on PO: {$record->po_number}",
                                'sourceable_type' => PurchaseOrder::class, // Link ke PO Konsinyasi
                                'sourceable_id' => $record->id,
                            ]);

                            // 3. Loop item yang valid & kurangi stok konsinyasi
                            foreach ($itemsReturnedData as $itemData) {
                                $productId = $itemData['product_id'];
                                // ==========================================================
                                // --- PERBAIKAN: Gunakan Qty Base UoM ---
                                // ==========================================================
                                $qtyToReturn = (int) $itemData['quantity_to_return_base']; // <-- Ambil Qty Base
                                // ==========================================================

                                // Tambahkan item ke Stock Transfer Return
                                $returnTransfer->items()->create([
                                     'product_id' => $productId,
                                     'quantity' => $qtyToReturn, // Simpan dalam Base UoM
                                     'uom' => $itemData['base_uom'], // Simpan Base UoM
                                     'quantity_picked' => $qtyToReturn, // Langsung dianggap "picked"
                                ]);

                                // Kurangi stok dari Lokasi Konsinyasi (FEFO)
                                $inventories = Inventory::where('location_id', $sourceConsignmentLocation->id)
                                    ->where('product_id', $productId)->where('avail_stock', '>', 0)
                                    ->orderBy('sled', 'asc')->get();

                                $remainingToDecrease = $qtyToReturn;
                                foreach ($inventories as $inventory) {
                                    if ($remainingToDecrease <= 0) break;
                                    $qtyFromThisBatch = min($remainingToDecrease, $inventory->avail_stock);

                                    $inventory->decrement('avail_stock', $qtyFromThisBatch);
                                    InventoryMovement::create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_change' => -$qtyFromThisBatch,
                                        'stock_after_move' => $inventory->avail_stock,
                                        'type' => 'RETURN_CONS_OUT', // Tipe movement baru
                                        'reference_type' => get_class($returnTransfer), // Referensi ke ST Return
                                        'reference_id' => $returnTransfer->id,
                                        'user_id' => $userId,
                                        'notes' => "Consignment buyout return"
                                    ]);

                                    // PICU EVENT FINANSIAL (PENTING)
                                    event(new ConsignmentStockConsumed($inventory, $qtyFromThisBatch, $returnTransfer));

                                    $remainingToDecrease -= $qtyFromThisBatch;
                                }
                            }

                            // 4. Cek dan Update Status PO Konsinyasi Asli
                            $record->loadMissing('items.product.uoms'); // Reload items PO asli
                            $fullyReturned = true; // Asumsi awal

                            foreach ($record->items as $poItem) {
                                // Hitung total qty (base uom) yang sudah di-return
                                $totalReturnedBaseQty = StockTransfer::where('sourceable_type', PurchaseOrder::class)
                                    ->where('sourceable_id', $record->id)
                                    ->where('status', 'completed')
                                    ->where('transfer_number', 'like', 'RTN-CON-%') // Filter hanya Return Konsinyasi
                                    ->join('stock_transfer_items', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
                                    ->where('stock_transfer_items.product_id', $poItem->product_id)
                                    ->sum('stock_transfer_items.quantity'); // Qty di ST item sudah base uom

                                // Konversi Qty PO asli ke Base UoM
                                $poUom = $poItem->product?->uoms->where('uom_name', $poItem->uom)->first();
                                $poQtyBase = (float)$poItem->quantity * ($poUom?->conversion_rate ?? 1);

                                if (round($totalReturnedBaseQty, 5) < round($poQtyBase, 5)) {
                                    $fullyReturned = false;
                                }
                            }

                            // Tentukan status baru
                            $newStatus = $fullyReturned ? 'fully_returned' : 'partially_returned';
                            $record->update(['status' => $newStatus]);


                            return $returnTransfer; // Kembalikan returnTransfer untuk notifikasi

                        }); // Akhir DB::transaction

                        Notification::make()->title('Consignment stock returned/marked for buyout.')
                            ->body("Stock Transfer {$returnTransfer->transfer_number} created. Please create a new PO (type: Consignment Buyout) and proceed with Goods Receipt.")
                            ->success()->send();

                        // Refresh form untuk update status PO di header
                        $this->refreshFormData(['status']);

                    } catch (\Exception $e) {
                        Notification::make()->title('Return Failed')->body($e->getMessage())->danger()->send();
                        $this->halt(); // Hentikan dan tetap buka modal
                    }
                }),

            Actions\DeleteAction::make()
                ->visible(fn() => $this->check($user, 'delete purchase orders')),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
