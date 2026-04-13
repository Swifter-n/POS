<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShipmentWorkbenchResource\Pages;
use App\Filament\Resources\ShipmentWorkbenchResource\RelationManagers;
use App\Models\PickingList;
use App\Models\Plant;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\ShipmentRoute;
use App\Models\ShipmentWorkbench;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Collection as EloquentCollection; // Ganti nama alias Collection
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ShipmentWorkbenchResource extends Resource
{
    protected static ?string $model = PickingList::class;
    protected static ?string $slug = 'shipment-workbench';

    // Beri nama yang sesuai
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';
    protected static ?string $navigationGroup = 'Inventory Management';
    protected static ?string $navigationLabel = 'Shipment Workbench';

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canView(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    // ==========================================================
    // --- SOLUSI PAKSA: TAMPILKAN TOMBOL ---
    // ==========================================================
    public static function canDeleteAny(): bool
    {
        // Log::info("ShipmentWorkbench: Forcing canDeleteAny() to TRUE for debugging.");
        // Seperti permintaan Anda, kita paksa muncul dulu.
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();

        // 1. Mulai query
        $query = parent::getEloquentQuery()
            // ->where('business_id', $user->business_id) // <-- PERBAIKAN: Baris ini dihapus
            ->where('status', 'completed') // <-- Kunci: Hanya yang selesai pick
            ->whereDoesntHave('shipments', function (Builder $q_shipment) {
                $q_shipment->where('status', '!=', 'cancelled'); // <-- Relasi M2M
                });

        // 2. Filter HANYA untuk dokumen Outbound (SO dan STO)
        $query->whereIn('sourceable_type', [
            SalesOrder::class,
            StockTransfer::class
        ]);

        // 3. Eager load relasi (PENTING untuk performa dan kolom)
        $query->with([
            'sourceable' => function ($morphTo) {
                // Eager load nested relasi di dalam SO/STO
                $morphTo->morphWith([
                    SalesOrder::class => ['customer'],
                    StockTransfer::class => ['destinationPlant', 'destinationOutlet'],
                ]);
            },
            'warehouse', // Untuk kolom "From Warehouse"
            'user' // Untuk kolom "Picker"
        ]);


        // 4. Filter berdasarkan Plant (HANYA jika user bukan Owner)
        //    (Gunakan $user->hasRole() dari Spatie, BUKAN self::userHasRole())
        if (!$user->hasRole('Owner') && !$user->hasRole('owner')) { // Asumsi Spatie Roles
             $user->loadMissing('locationable'); // Load relasi
             $userPlantId = null;
             if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
                 $userPlantId = $user->locationable->plant_id;
             }

             // Terapkan filter HANYA jika user terikat ke Plant
             if ($userPlantId) {
                 // Asumsikan semua PL (termasuk dari SO) punya warehouse_id
                 $query->where('warehouse_id', '!=', null)
                       ->whereHas('warehouse', fn(Builder $q) => $q->where('plant_id', $userPlantId));
             }
             // Jika user bukan Owner dan tidak punya Plant (misal Admin/Dispatcher),
             // dia bisa melihat semua (akan difilter oleh business_id di bawah)
        }

        // ==========================================================
        // --- PERBAIKAN: Filter 'business_id' dipindahkan ke sini ---
        // ==========================================================
        // Filter berdasarkan Business ID melalui relasi (Ini adalah filter utama)
        $query->whereHas('warehouse', function (Builder $q_warehouse) use ($user) {
            $q_warehouse->whereIn('plant_id', function ($subQuery) use ($user) {
                $subQuery->select('id')
                         ->from('plants') // Asumsi nama tabel adalah 'plants'
                         ->where('business_id', $user->business_id);
            });
        });

        // ==========================================================
        // --- LOGGING (Permintaan ke-4 Anda) ---
        // ==========================================================
        $finalCount = $query->clone()->count();
        Log::info("ShipmentWorkbench: getEloquentQuery() found {$finalCount} records.");
        // ==========================================================

        return $query;
    }
    // ==========================================================


    public static function table(Table $table): Table
    {
        return $table
            ->query(self::getEloquentQuery()) // Gunakan query di atas
            ->columns([
                Tables\Columns\TextColumn::make('picking_list_number')->label('Picking List #')->searchable(),

                // (Kolom 'sourceable.document_number' sudah benar)
                Tables\Columns\TextColumn::make('source_doc_key') // <-- 1. Ubah key agar tidak bentrok
                    ->label('Source Doc')
                    ->getStateUsing(fn (Model $record): ?string => // <-- 2. Ganti ke getStateUsing
                        // 3. Logika Anda yang sudah benar dipindahkan ke sini
                        $record->sourceable?->so_number ?? $record->sourceable?->transfer_number
                    )
                    ->url(function($record): string {
                        // Link ke SO atau STO
                        if ($record->sourceable_type === SalesOrder::class)
                            return SalesOrderResource::getUrl('edit', ['record' => $record->sourceable_id]);
                        if ($record->sourceable_type === StockTransfer::class)
                            return StockTransferResource::getUrl('edit', ['record' => $record->sourceable_id]);
                        // --- UPDATE: Link PO Dihapus ---
                        return '#';
                    }, true),
                // ==========================================================

                Tables\Columns\TextColumn::make('warehouse.name')->label('From Warehouse'),

                // Kolom Tujuan (Dinamis)
                Tables\Columns\TextColumn::make('destination_display')
                    ->label('Destination')
                    ->getStateUsing(function (Model $record) {
                        // $record->loadMissing('sourceable'); // <-- DIHAPUS (Sudah di Eager Load)
                        $sourceable = $record->sourceable;

                        // Muat relasi tujuan dari SO/STO
                        if ($sourceable instanceof SalesOrder) {
                            // $sourceable->loadMissing('customer'); // <-- DIHAPUS
                            return $sourceable->customer?->name . ' (Customer)';
                        }
                        if ($sourceable instanceof StockTransfer) {
                             // $sourceable->loadMissing(['destinationPlant', 'destinationOutlet']); // <-- DIHAPUS
                             if ($sourceable->destinationPlant) return $sourceable->destinationPlant->name . ' (Plant)';
                             if ($sourceable->destinationOutlet) return $sourceable->destinationOutlet->name . ' (Outlet)';
                        }
                        // --- UPDATE: Logika PO Dihapus ---
                        return 'N/A';
                    }),
                Tables\Columns\TextColumn::make('user.name')->label('Picker'),
                Tables\Columns\TextColumn::make('completed_at')->dateTime()->sortable(),
            ])
            ->filters([
                // ==========================================================
                // --- ALTERNATIF UNTUK MENGHINDARI BUG 'Relation, null given' ---
                // ==========================================================
                Tables\Filters\SelectFilter::make('plant_id') // Ganti nama key
                    ->label('Plant')
                    // 1. Isi opsi secara manual
                    ->options(function () {
                        // Ambil semua plant yang relevan (pastikan tidak ada null)
                        return Plant::where('business_id', Auth::user()->business_id)
                            ->where('status', true)
                            ->whereNotNull('name') // <-- Tambahan keamanan
                            ->pluck('name', 'id');
                    })
                    // 2. Terapkan query secara manual
                    ->query(function (Builder $query, array $data): Builder {
                        $plantId = $data['value'];
                        if (empty($plantId)) {
                            return $query;
                        }

                        // Terapkan filter di relasi 'warehouse'
                        return $query->whereHas('warehouse', function (Builder $q_warehouse) use ($plantId) {
                            $q_warehouse->where('plant_id', $plantId);
                        });
                    })
                    ->preload()
                    ->searchable(), // <- Searchable() sekarang aman digunakan
                // ==========================================================
            ])
            ->actions([
                // Tidak ada
            ])
            // ==========================================================
            // --- 'bulkActions' DIGANTI DENGAN 'headerActions' ---
            // ==========================================================
            ->headerActions([
                Tables\Actions\Action::make('consolidateToShipment')
                    ->label('Consolidate to Shipment')
                    ->icon('heroicon-o-truck')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Consolidate Picking Lists')
                    ->modalDescription('Select the completed picking lists you want to consolidate into a single shipment.')
                    ->slideOver()
                    ->form([
                        DatePicker::make('scheduled_for')
                            ->label('Scheduled For Date')
                            ->default(now())
                            ->required(),
                        DateTimePicker::make('estimated_time_of_arrival')
                            ->label('Estimated Time of Arrival (ETA)')
                            ->default(now()->addDay())
                            ->nullable(),

                        CheckboxList::make('record_ids')
                            ->label('Available Picking Lists')
                            ->options(function () {
                                $query = static::getEloquentQuery();
                                return $query->get()->mapWithKeys(function ($pl) {
                                    $pl->loadMissing('sourceable');
                                    $docNumber = $pl->sourceable?->so_number ?? $pl->sourceable?->transfer_number ?? 'N/A';
                                    return [$pl->id => "{$pl->picking_list_number} (Source: {$docNumber})"];
                                });
                            })
                            ->searchable()
                            ->required()
                            ->columns(1),
                    ])
                    ->action(function (array $data) {
                        $recordIds = $data['record_ids'];
                        $scheduledFor = $data['scheduled_for'];
                        $eta = $data['estimated_time_of_arrival'];
                        $records = PickingList::find($recordIds);

                        if ($records->isEmpty()) {
                             Notification::make()->title('No records selected.')->warning()->send();
                             return;
                        }

                        $records->loadMissing([
                            'sourceable',
                            'items.product',
                            'warehouse.plant',
                        ]);

                        // --- LOGIKA VALIDASI ---
                        $firstRecord = $records->first();
                        $firstSourceable = $firstRecord->sourceable;
                        if (!$firstSourceable) {
                             Notification::make()->title('Consolidation Failed')->body("Picking List {$firstRecord->picking_list_number} is missing its source document.")->warning()->send();
                             return;
                        }

                        $firstSourcePlantId = $firstRecord->warehouse?->plant_id;
                        $firstSourceWarehouseId = $firstRecord->warehouse_id;
                        if (!$firstSourcePlantId || !$firstSourceWarehouseId) {
                             Notification::make()->title('Consolidation Failed')
                                ->body("Picking List {$firstRecord->picking_list_number} is missing critical Plant or Warehouse ID data. Cannot create shipment.")
                                ->warning()->send();
                             return;
                        }
                        $firstType = $firstSourceable::class;

                        // Eager load kondisional (setelah tahu $firstType)
                        if ($firstType === SalesOrder::class) {
                            // [PERBAIKAN] Muat relasi customer DENGAN area_id DAN shipping_cost
                            $records->loadMissing('sourceable.customer:id,area_id', 'sourceable:id,shipping_cost');
                        } elseif ($firstType === StockTransfer::class) {
                            $records->loadMissing(['sourceable.destinationPlant:id,area_id', 'sourceable.destinationOutlet:id,area_id']);
                        }

                        $firstDestKey = null;
                        if ($firstType === SalesOrder::class) $firstDestKey = 'customer_' . $firstSourceable->customer_id;
                        elseif ($firstType === StockTransfer::class) $firstDestKey = 'plant_' . $firstSourceable->destination_plant_id ?? 'outlet_' . $firstSourceable->destination_outlet_id;

                        foreach($records as $record) {
                            $sourceable = $record->sourceable;
                            if (!$sourceable) {
                                Notification::make()->title('Consolidation Failed')->body("Picking List {$record->picking_list_number} is missing its source document.")->warning()->send();
                                return;
                            }
                            if ($sourceable::class !== $firstType) {
                                Notification::make()->title('Consolidation Failed')->body('Cannot mix Sales Orders and Stock Transfers in the same shipment.')->warning()->send();
                                return;
                            }
                            if ($record->warehouse?->plant_id !== $firstSourcePlantId) {
                                 Notification::make()->title('Consolidation Failed')->body('All selected Picking Lists must originate from the same Source Plant.')->warning()->send();
                                 return;
                            }
                            $currentDestKey = null;
                            if ($sourceable instanceof SalesOrder) $currentDestKey = 'customer_' . $sourceable->customer_id;
                            elseif ($sourceable instanceof StockTransfer) $currentDestKey = 'plant_' . $sourceable->destination_plant_id ?? 'outlet_' . $sourceable->destination_outlet_id;
                            if ($currentDestKey !== $firstDestKey) {
                                 Notification::make()->title('Consolidation Failed')->body("All selected documents must share the same destination (Customer/Plant/Outlet).")->warning()->send();
                                 return;
                            }
                        }

                        // --- LOGIKA EKSEKUSI ---
                        try {
                            $shipment = DB::transaction(function () use ($records, $firstType, $firstSourceable, $firstSourcePlantId, $firstSourceWarehouseId, $firstRecord, $scheduledFor, $eta) {

                                $allItems = new Collection();
                                foreach ($records as $pickingList) {
                                    foreach ($pickingList->items as $item) {
                                        $qtyBase = (float)($item->quantity_picked ?? $item->total_quantity_to_pick);
                                        if ($qtyBase > 0) $allItems->push(['product_id' => $item->product_id, 'quantity_base' => $qtyBase]);
                                    }
                                }
                                $groupedItems = $allItems->groupBy('product_id');
                                $shipmentItemsData = $groupedItems->map(fn ($g) => [
                                    'product_id' => $g->first()['product_id'],
                                    'quantity' => $g->sum('quantity_base'),
                                ])->values()->toArray();
                                if (empty($shipmentItemsData)) throw new \Exception('No items found (quantity picked might be zero).');

                                // ==========================================================
                                // --- PERBAIKAN: Logika Kalkulasi transport_cost ---
                                // ==========================================================
                                $transportCost = 0;
                                $originAreaId = $firstRecord->warehouse->plant->area_id ?? null;
                                $destinationAreaId = null;

                                if ($firstSourceable instanceof StockTransfer) {
                                    // --- Alur STO: Hitung ulang ---
                                    $destinationAreaId = $firstSourceable->destinationPlant?->area_id ?? $firstSourceable->destinationOutlet?->area_id;

                                    if ($firstSourcePlantId && $destinationAreaId) {
                                        $route = ShipmentRoute::where('source_plant_id', $firstSourcePlantId)
                                            ->whereHas('destinationAreas', fn($q) => $q->where('areas.id', $destinationAreaId))
                                            ->first();
                                        if ($route) {
                                            $transportCost = $route->base_cost ?? 0;
                                            $areaPivot = $route->destinationAreas()->where('area_id', $destinationAreaId)->first();
                                            if ($areaPivot) $transportCost += $areaPivot->pivot->surcharge ?? 0;
                                        } else {
                                             Log::warning("ShipmentRoute (STO) not found for Source Plant ID: {$firstSourcePlantId} to Area ID: {$destinationAreaId}");
                                        }
                                    } else {
                                         Log::warning("Cannot calculate transport cost (STO). Missing Source Plant ID ({$firstSourcePlantId}) or Destination Area ID ({$destinationAreaId}).");
                                    }

                                } elseif ($firstSourceable instanceof SalesOrder) {
                                    // --- Alur SO: Salin dari SO ---
                                    // Kita ambil biaya dari SEMUA SO yang di-konsolidasi
                                    $transportCost = $records->pluck('sourceable.shipping_cost')->sum();
                                    Log::info("Calculated transport cost from sum of " . $records->count() . " Sales Orders: {$transportCost}");
                                }
                                // ==========================================================


                                $businessId = $firstRecord->warehouse->plant->business_id ?? $firstSourceable->business_id;
                                $shipmentData = [
                                    'shipment_number' => 'DO-WAVE-' . date('Ym') . '-' . random_int(1000, 9999),
                                    'business_id' => $businessId,
                                    'status' => 'ready_to_ship',
                                    'source_plant_id' => $firstSourcePlantId,
                                    'source_warehouse_id' => $firstSourceWarehouseId,
                                    'picker_user_id' => $firstRecord->user_id,
                                    'transport_cost' => $transportCost, // <-- Nilai yang sudah diperbaiki
                                    'scheduled_for' => $scheduledFor,
                                    'estimated_time_of_arrival' => $eta,
                                ];
                                if ($firstType === SalesOrder::class) {
                                    $shipmentData['customer_id'] = $firstSourceable->customer_id;
                                } elseif ($firstType === StockTransfer::class) {
                                    $shipmentData['destination_plant_id'] = $firstSourceable->destination_plant_id;
                                    $shipmentData['destination_outlet_id'] = $firstSourceable->destination_outlet_id;
                                }
                                $shipment = Shipment::create($shipmentData);
                                $shipment->items()->createMany($shipmentItemsData);
                                $sourceModels = $records->pluck('sourceable');
                                $sourceIds = $sourceModels->pluck('id');
                                $pivotData = [];
                                foreach ($sourceIds as $id) $pivotData[$id] = ['business_id' => $businessId];
                                if ($firstType === SalesOrder::class) $shipment->salesOrders()->attach($pivotData);
                                elseif ($firstType === StockTransfer::class) $shipment->stockTransfers()->attach($pivotData);
                                $pickingListIds = $records->pluck('id');
                                $plPivotData = [];
                                foreach ($pickingListIds as $id) $plPivotData[$id] = ['business_id' => $businessId];
                                $shipment->pickingLists()->attach($plPivotData);
                                $records->each->update(['status' => 'shipped']);
                                return $shipment;
                            });

                            Notification::make()->title('Shipment Consolidated!')
                                ->body("Shipment {$shipment->shipment_number} created from {$records->count()} picking list(s).")
                                ->success()->send();
                            return redirect(ShipmentResource::getUrl('edit', ['record' => $shipment]));

                        } catch (\Exception $e) {
                             Log::error("Shipment Consolidation Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                             Notification::make()->title('Consolidation Failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                // KOSONG (Dipindahkan ke headerActions)
            ]);
    }

     public static function getPages(): array
    {
        return [
            // Ganti halaman default ke 'List'
            'index' => Pages\ListShipmentWorkbenches::route('/'),
        ];
    }
}

