<?php

namespace App\Filament\Resources\GoodsReturnResource\Pages;

use App\Filament\Resources\GoodsReturnResource;
use App\Filament\Resources\ShipmentResource;
use App\Models\GoodsReturn;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EditGoodsReturn extends EditRecord
{
    use HasPermissionChecks; // <-- [BARU] (Asumsi Anda membutuhkannya)
    protected static string $resource = GoodsReturnResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);
        $this->getRecord()->loadMissing('plant', 'salesOrder', 'customer', 'createdBy');
    }
    /**
     * Tampilkan Detail Header sebagai Placeholder (Read-only)
     */
    public function form(Form $form): Form
    {
        $record = $this->getRecord();
        // Muat relasi yang diperlukan untuk Placeholder
        //$record->loadMissing('plant', 'salesOrder', 'customer', 'createdBy');

        return $form->schema([
            Section::make('Goods Return Details')
                ->schema([
                    Placeholder::make('return_number')->content($record->return_number),

                    // (Perbaikan 'badge' Anda sudah benar)
                    Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'pending' => 'Pending',
                            'receiving' => 'Receiving',
                            'received' => 'Received',
                            'cancelled' => 'Cancelled',
                        ])
                        ->default($record->status)
                        ->label('Status')
                        ->disabled(),

                    // Placeholder ini sekarang akan berfungsi karena relasi sudah di-load di mount()
                    Placeholder::make('salesOrder.so_number')
                        ->label('From SO')
                        ->content(fn (GoodsReturn $record): ?string => $record->salesOrder?->so_number ?? 'N/A'),

                    Placeholder::make('customer.name')
                        ->label('Customer')
                        ->content(fn (GoodsReturn $record): ?string => $record->customer?->name ?? 'N/A'),

                    Placeholder::make('plant.name')
                        ->label('Return Plant')
                        ->content(fn (GoodsReturn $record): ?string => $record->plant?->name ?? 'N/A'),

                    Placeholder::make('createdBy.name')
                        ->label('Created By')
                        ->content(fn (GoodsReturn $record): ?string => $record->createdBy?->name ?? 'N/A'),
                    Placeholder::make('notes')->content($record->notes)->columnSpanFull(),
                ])->columns(3), // 3 kolom
        ]);
    }


    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();

        return [
            // ==========================================================
            // --- ACTION BARU: Menerima Barang Retur (dari POD) ---
            // ==========================================================
            Actions\Action::make('receiveReturnItems')
                ->label('Receive Return Items')
                ->color('success')->icon('heroicon-o-archive-box-arrow-down')
                ->requiresConfirmation()
                // Hanya muncul jika status PENDING (dibuat oleh POD)
                ->visible(fn () => $record->status === 'pending' && $this->check($user, 'approve goods return')) // Ganti permission jika perlu
                ->form(function (GoodsReturn $record): array {
                    $record->loadMissing('items.product');

                    // 1. Tentukan Lokasi Tujuan (Zona 'RETURN')
                    // $returnZoneId = Zone::where('code', 'RET')->value('id');
                    // if (!$returnZoneId) {
                    //     Notification::make()->title('Setup Error')->body('Zone "RETURN" not found.')->danger()->send();
                    //     return [];
                    // }

                    // $destinationOptions = Location::where('locatable_type', Warehouse::class)
                    //             ->whereHas('locatable', fn($q) => $q->where('plant_id', $record->plant_id))
                    //             ->where('zone_id', $returnZoneId)
                    //             ->where('status', true)
                    //             ->pluck('name', 'id');

                    $plantId = $record->plant_id;

                        if (!$plantId && $record->destinationOutlet?->supplying_plant_id) {
                            $plantId = $record->destinationOutlet->supplying_plant_id;
                            Log::info("Fallback used: plant_id taken from supplying_plant_id of destination outlet ID {$record->destinationOutlet->id}");
                        }

                        // Tentukan Zone 'RETURN' (bisa diganti sesuai kebutuhan)
                        $returnZoneId = Zone::where('code', 'RET')->first()?->id;

                        // Cari lokasi aktif di zone RETURN berdasarkan Plant
                        $destinationOptions = [];

                        if ($plantId && $returnZoneId) {
                            $destinationOptions = Location::where('locatable_type', Warehouse::class)
                                ->whereIn('locatable_id', Warehouse::where('plant_id', $plantId)->pluck('id'))
                                ->where('zone_id', $returnZoneId)
                                ->where('status', true)
                                ->pluck('name', 'id');
                        } else {
                            Log::warning("No valid plant_id or returnZone found for Receive Return Items on shipment ID {$record->id}");
                        }

                        // Optional: beri notifikasi kalau gagal dapat lokasi
                        if ($destinationOptions->isEmpty()) {
                            Notification::make()
                                ->title('Receiving Location Not Found')
                                ->body('Tidak ada lokasi aktif yang ditemukan untuk plant/zone RETURN ini.')
                                ->warning()
                                ->send();
                        }

                    // 2. Siapkan data Repeater
                    $repeaterItems = $record->items->map(function ($item) {
                        // $item adalah GoodsReturnItem
                        return [
                            'goods_return_item_id' => $item->id,
                            'product_id' => $item->product_id,
                            'product_name' => $item->product?->name,
                            'quantity_expected' => $item->quantity, // (Base UoM)
                            'base_uom' => $item->uom, // (Base UoM)
                            'quantity_received' => $item->quantity, // Default
                            'received_uom' => $item->uom, // Default
                        ];
                    })->toArray();

                    return [
                        Select::make('destination_location_id')
                            ->label('Destination Location (Zone: RETURN)')
                            ->options($destinationOptions)
                            ->required()
                            ->searchable()->preload(),

                        Repeater::make('items_confirmation')
                            ->label('Item Confirmation')
                            ->schema([
                                Placeholder::make('product_name')->content(fn(Get $get) => $get('product_name')),
                                Placeholder::make('quantity_expected_display')
                                    ->content(fn(Get $get) => $get('quantity_expected') . ' ' . $get('base_uom')),

                                TextInput::make('quantity_received')->numeric()->required(),
                                Select::make('received_uom')
                                    ->options(fn(Get $get) => ProductUom::where('product_id', $get('product_id'))->pluck('uom_name', 'uom_name'))
                                    ->required(),

                                TextInput::make('batch')->label('New Batch')->required(),
                                DatePicker::make('sled')->label('New SLED')->required(),

                                Hidden::make('goods_return_item_id'),
                                Hidden::make('product_id'),
                                Hidden::make('quantity_expected'), // Base UoM
                                Hidden::make('base_uom'),
                            ])
                            ->default($repeaterItems)
                            ->addable(false)->deletable(false)
                            ->columns(6)
                    ];
                })
                ->action(function (GoodsReturn $record, array $data) use ($user) {
                    try {
                        DB::transaction(function () use ($record, $data, $user) {
                            $destinationLocationId = $data['destination_location_id'];
                            $destinationLocation = Location::find($destinationLocationId);

                            $record->update([
                                'status' => 'receiving',
                                'destination_location_id' => $destinationLocationId,
                                'warehouse_id' => $destinationLocation?->locatable_id, // Asumsi 'locatable' adalah Warehouse
                            ]);

                            foreach($data['items_confirmation'] as $itemData) {
                                $product = Product::find($itemData['product_id']);
                                $product->loadMissing('uoms');

                                // Konversi Qty Diterima
                                $inputQty = (float) $itemData['quantity_received'];
                                $inputUomName = $itemData['received_uom'];
                                $uomData = $product->uoms->firstWhere('uom_name', $inputUomName);
                                $conversionRate = $uomData?->conversion_rate ?? 1;
                                $receivedBaseQty = $inputQty * $conversionRate;

                                // Validasi (tidak boleh > dari yg diharapkan)
                                $expectedBaseQty = (float) $itemData['quantity_expected'];
                                if (round($receivedBaseQty, 5) > round($expectedBaseQty, 5)) {
                                    throw new \Exception("Qty Received for {$product->name} ({$receivedBaseQty} base) cannot exceed expected return qty ({$expectedBaseQty} base).");
                                }

                                // Tambah Stok ke Zona RETURN
                                $inventory = Inventory::firstOrCreate(
                                    ['location_id' => $destinationLocationId, 'product_id' => $itemData['product_id'], 'batch' => $itemData['batch']],
                                    ['sled' => $itemData['sled'], 'avail_stock' => 0, 'business_id' => $record->business_id]
                                );
                                $inventory->increment('avail_stock', $receivedBaseQty);

                                InventoryMovement::create([
                                    'inventory_id' => $inventory->id, 'quantity_change' => $receivedBaseQty,
                                    'stock_after_move' => $inventory->avail_stock, 'type' => 'RETURN_IN',
                                    'reference_type' => get_class($record), 'reference_id' => $record->id, 'user_id' => $user->id,
                                    'notes' => "Goods Return (POD) received into {$destinationLocation->name}"
                                ]);
                            }

                            $record->update([
                                'status' => 'received',
                                'approved_by_user_id' => $user->id
                            ]);
                        });
                        Notification::make()->title('Return Received Successfully!')->body('Stock has been added to the RETURN zone.')->success()->send();
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Log::error("Receive GoodsReturn Error: " . $e->getMessage());
                        Notification::make()->title('Return Receive Failed')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

            // ==========================================================
            // --- ACTION LAMA (completeReturn) DIHAPUS ---
            // (Karena fungsionalitasnya sudah dihandle StockTransferResource)
            // ==========================================================
            // Actions\Action::make('completeReturn') ... (DIHAPUS)

            Actions\Action::make('cancelReturn')
                ->label('Cancel Return')
                ->color('danger')->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                // Hanya bisa cancel DRAFT (Manual) atau PENDING (POD)
                ->visible(fn () => in_array($record->status, ['draft', 'pending']) && $this->check($user, 'delete goods returns'))
                ->action(function (GoodsReturn $record) {
                    try {
                         DB::transaction(function () use ($record) {
                            $record->items()->delete();
                            $record->update(['status' => 'cancelled']);
                         });
                         Notification::make()->title('Goods Return Cancelled.')->warning()->send();

                         // ==========================================================
                         // --- PERBAIKAN: Ganti 'refreshFormData' dengan 'redirect' ---
                         // ==========================================================
                         // $this->refreshFormData($this->getRecord()->toArray()); // <-- BUG 'array_flip'
                         return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                         // ==========================================================

                    } catch (\Exception $e) {
                         Notification::make()->title('Cancellation Failed')->body($e->getMessage())->danger()->send();
                         $this->halt();
                    }
                }),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

}
