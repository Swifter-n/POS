<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesOrderResource\Pages;
use App\Filament\Resources\SalesOrderResource\RelationManagers;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\PickingListItemSource;
use App\Models\Plant;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\SalesOrder;
use App\Models\ShipmentRoute;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\PricingService;
use App\Services\PutawayStrategyService;
use App\Traits\HasPermissionChecks;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SalesOrderResource extends Resource
{
    use HasPermissionChecks;
    protected static ?string $model = SalesOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'Sales Management';

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
        if (!$user || !$user->business_id) {
             return parent::getEloquentQuery()->whereRaw('0 = 1');
        }
        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);

        if (self::userHasRole('Owner')) {
            return $query;
        }

        if ($user->hasRole('Salesman')) {
             $query->where('salesman_id', $user->id);
             return $query;
        }

        $userPlantId = null;
        $user->loadMissing('locationable');

        // HANYA cek Warehouse (DC/Plant)
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        }

        if ($userPlantId) {
            // Tampilkan SO HANYA yang di-assign ke Plant/DC user
            $query->where('supplying_plant_id', $userPlantId);
        } else {
            // Jika user BUKAN Owner/Salesman dan BUKAN staf Warehouse, sembunyikan.
            $query->whereRaw('0 = 1');
        }
        // ==========================================================

        return $query;
    }

    public static function canCreate(): bool
    {
        $user = Auth::user();
        if (!$user) return false;
        if (!self::userHasPermission('create sales orders')) return false;
        if (self::userHasRole('Owner') || $user->hasRole('Salesman')) return true;

        $user->loadMissing('locationable');
         if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            return true;
        }

        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Wizard::make([
                    Forms\Components\Wizard\Step::make('Order Details')
                        ->schema([
                            Forms\Components\Select::make('customer_id')
                                ->relationship('customer', 'name', modifyQueryUsing: function (Builder $query) {
                                    $user = Auth::user();
                                    $query->where('business_id', $user->business_id)->where('status', true);

                                    if ($user->hasRole('Owner')) {
                                        return $query;
                                    }
                                    if ($user->hasRole('Salesman')) {
                                        // (Logika Salesman Anda sudah benar)
                                        $user->loadMissing('salesTeams');
                                        $salesTeamIds = $user->salesTeams->pluck('id');
                                        if ($salesTeamIds->isEmpty()) return $query->whereRaw('0 = 1');
                                        $areaIds = DB::table('area_sales_team_pivot')
                                                     ->whereIn('sales_team_id', $salesTeamIds)
                                                     ->pluck('area_id')
                                                     ->unique();
                                        if ($areaIds->isEmpty()) return $query->whereRaw('0 = 1');
                                        $query->whereIn('area_id', $areaIds);
                                        return $query;
                                    }

                                    return $query;
                                })
                                ->searchable()->preload()->required()->live()
                                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                    $customer = Customer::find($state);
                                    if ($customer) {
                                        $set('terms_of_payment_id', $customer->terms_of_payment_id);
                                        if ($customer->supplying_plant_id) {
                                            $set('supplying_plant_id', $customer->supplying_plant_id);
                                        }
                                    }
                                }),

                            Forms\Components\Select::make('supplying_plant_id')
                                ->label('Supplying Plant / DC')
                                ->options(function () {
                                    $user = Auth::user();
                                    $query = Plant::where('business_id', $user->business_id)
                                                  ->where('status', true)
                                                  ->whereIn('type', ['DISTRIBUTION']);
                                    if (!$user->hasRole('Owner') && !$user->hasRole('Salesman')) {
                                        $userPlantId = null;
                                        $user->loadMissing('locationable');
                                        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
                                            $userPlantId = $user->locationable->plant_id;
                                        }

                                        if ($userPlantId) {
                                            $query->where('id', $userPlantId);
                                        } else {
                                            $query->whereRaw('0 = 1');
                                        }
                                    }
                                    // ==========================================================

                                    return $query->pluck('name', 'id');
                                })
                                ->searchable()->preload()->required()
                                ->live(),

                            Forms\Components\Placeholder::make('csl')
                                 ->label('Customer Service Level')
                                 ->content(fn (Get $get) => Customer::find($get('customer_id'))?->customerServiceLevel?->name ?? 'N/A'),

                            Forms\Components\Select::make('terms_of_payment_id')
                                ->relationship('termsOfPayment', 'name')
                                ->label('Terms of Payment')
                                ->searchable()->preload()->required(),

                            Forms\Components\Select::make('salesman_id')->label('Salesman')
                                // Opsi disesuaikan (logika lama Anda)
                                ->options(function (Get $get) {
                                    // (Logika options salesman Anda sebelumnya)
                                    $customerId = $get('customer_id');
                                    if (!$customerId) return User::where('business_id', Auth::user()->business_id)->whereHas('position', fn (Builder $q) => $q->where('name', 'Salesman'))->pluck('name', 'id'); // Fallback

                                    $customer = Customer::find($customerId);
                                    if (!$customer || !$customer->area_id) return User::where('business_id', Auth::user()->business_id)->whereHas('position', fn (Builder $q) => $q->where('name', 'Salesman'))->pluck('name', 'id'); // Fallback

                                    $salesTeamIds = DB::table('area_sales_team_pivot')->where('area_id', $customer->area_id)->pluck('sales_team_id');
                                    if ($salesTeamIds->isEmpty()) return User::where('business_id', Auth::user()->business_id)->whereHas('position', fn (Builder $q) => $q->where('name', 'Salesman'))->pluck('name', 'id'); // Fallback

                                    return User::query()
                                        ->where('business_id', Auth::user()->business_id)
                                        ->whereHas('position', fn (Builder $query) => $query->where('name', 'Salesman'))
                                        ->whereHas('salesTeams', fn (Builder $query) => $query->whereIn('sales_teams.id', $salesTeamIds))
                                        ->pluck('name', 'id');
                                })
                                ->searchable()->required(),
                            Forms\Components\TextInput::make('customer_po_number')->label('Customer PO Number'),
                            Forms\Components\DatePicker::make('order_date')->required()->default(now()),
                            Forms\Components\Select::make('payment_type')->options(['cash' => 'Cash', 'credit' => 'Credit'])->required()->default('credit'),

                            // Field total (read-only)
                            Forms\Components\Grid::make(3)
                                ->schema([
                                    Forms\Components\TextInput::make('sub_total')->numeric()->readOnly()->prefix('Rp')->default(0),
                                    Forms\Components\TextInput::make('total_discount')->numeric()->readOnly()->prefix('Rp')->default(0),
                                    Forms\Components\TextInput::make('tax')->numeric()->readOnly()->prefix('Rp')->default(0),
                                    Forms\Components\TextInput::make('shipping_cost')->numeric()->readOnly()->prefix('Rp')->default(0),
                                    Forms\Components\TextInput::make('grand_total')
                                        ->label('Grand Total')
                                        ->numeric()->readOnly()->prefix('Rp')->default(0)
                                        ->extraAttributes(['class' => 'font-bold text-lg']),
                                ]),

                            Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
                        ])->columns(2),

                ])->persistStepInQueryString()->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('so_number')->searchable(),
                Tables\Columns\TextColumn::make('customer.name')->searchable(),
                Tables\Columns\TextColumn::make('supplyingPlant.name')
                    ->label('Supplying Plant')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                     'gray' => 'draft',
                     'warning' => 'pending_approval',
                     'success' => 'approved',
                     'info' => 'processing', // (PL Dibuat)
                     'primary' => 'ready_to_ship', // (Picking Selesai)
                     'purple' => 'shipping', // (DO Dibuat)
                     'success' => 'delivered', // (Terkirim)
                     'danger' => 'cancelled',
                 ])->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('grand_total')->money('IDR'),
                Tables\Columns\TextColumn::make('order_date')->date()->sortable(),
            ])
            ->filters([
               Tables\Filters\SelectFilter::make('salesman_id')
                    ->relationship('salesman', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('supplying_plant_id')
                    ->label('Supplying Plant')
                    ->relationship('supplyingPlant', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    self::getApproveAction(),
                    self::getGeneratePickingListAction(),
                    self::getGoToWorkbenchAction(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    // Tambahkan `Tables\Actions\` di depan `Action`
public static function getApproveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label('Approve Order')->icon('heroicon-o-check-badge')->color('success')
            ->requiresConfirmation()
            ->action(function (SalesOrder $record) {
                // (Logika Credit Limit Anda sudah benar)
                $customer = $record->customer;
                if ($customer && $customer->credit_limit > 0 && ($customer->current_balance + $record->grand_total) > $customer->credit_limit) {
                    Notification::make()->title('Credit Limit Exceeded!')->danger()->send();
                    return;
                }
                DB::transaction(function () use ($record, $customer) {
                    $record->update(['status' => 'approved']);
                    if ($record->payment_type === 'credit' && $customer) {
                        $customer->increment('current_balance', $record->grand_total);
                    }
                });
                Notification::make()->title('Sales Order Approved')->success()->send();
            })
            ->hidden(fn (SalesOrder $record) => !in_array($record->status, ['draft', 'pending_approval']) || !self::userHasPermission('approve sales orders'));
    }

    public static function getGeneratePickingListAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('generatePickingList')
            ->label('Generate Picking List')
            ->icon('heroicon-o-list-bullet')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (SalesOrder $record) =>
                $record->status === 'approved' &&
                self::userHasPermission('create picking list')
            )
            ->form([
                Forms\Components\Select::make('source_plant_id')
                    ->label('Source Plant')
                    ->options(function (SalesOrder $record): array {
                         $record->loadMissing('supplyingPlant');
                         if ($record->supplyingPlant) {
                             return [$record->supplyingPlant->id => $record->supplyingPlant->name];
                         }
                        return Plant::where('business_id', Auth::user()->business_id)
                               ->whereIn('type', ['DISTRIBUTION'])
                               ->pluck('name', 'id')->toArray();
                    })
                    ->default(fn(SalesOrder $record) => $record->supplying_plant_id)
                    ->required()
                    ->disabled()
                    ->live(),

                Forms\Components\Select::make('source_warehouse_id')
                    ->label('Pick Items From Warehouse')
                    ->options(function (Get $get, SalesOrder $record): array {
                        $plantId = $get('source_plant_id');
                        if (!$plantId) return [];

                        $productTypes = $record->items()->with('product:id,product_type')->get()
                                    ->pluck('product.product_type')->filter()->unique()->values();
                        $warehouseTypes = $productTypes->map(function ($productType) {
                            $map = [
                                'finished_good' => ['FINISHED_GOOD', 'DISTRIBUTION', 'MERCHANDISE'],
                                'raw_material' => ['RAW_MATERIAL', 'COLD_STORAGE'],
                            ];
                            return $map[$productType] ?? ['MAIN', 'OTHER', 'GENERAL'];
                        })->flatten()->unique()->all();

                        return Warehouse::where('plant_id', $plantId)
                            ->whereIn('type', $warehouseTypes)
                            ->where('status', true)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->required()
                    ->searchable()
                    ->live()
                    ->helperText('Pilih gudang spesifik di plant sumber untuk picking.'),

                Forms\Components\Select::make('assigned_user_id')
                    ->label('Assign Picking Task To')
                    ->options(function (Get $get): array {
                        $sourceWarehouseId = $get('source_warehouse_id');
                        if (!$sourceWarehouseId) return [];

                        return User::where('locationable_type', Warehouse::class)
                                    ->where('locationable_id', $sourceWarehouseId)
                                    ->where('status', true)
                                    ->whereHas('position', fn ($q) => $q->whereIn('name', ['Staff Gudang', 'Manager Gudang']))
                                    ->pluck('name', 'id')
                                    ->toArray();
                    })
                    ->preload()
                    ->required()
                    ->searchable()
                    ->helperText('Hanya menampilkan staf yang ditugaskan di Warehouse yang dipilih.'),
            ])
            ->action(function (SalesOrder $record, array $data) {
                try {
                    DB::transaction(function () use ($record, $data) {
                        // 1. Validasi Awal
                        if ($record->pickingLists()->where('status', '!=', 'cancelled')->exists()) {
                            throw ValidationException::withMessages(['error' => 'An active picking list already exists.']);
                        }

                        $record->loadMissing('customer.customerServiceLevel', 'items.product.uoms');

                        // Cek Prioritas Customer
                        $currentCustomerCSL = $record->customer?->customerServiceLevel;
                        $currentPriority = $currentCustomerCSL ? $currentCustomerCSL->priority_order : 999;
                        $productIdsInThisOrder = $record->items->pluck('product_id');

                        $higherPriorityOrders = SalesOrder::where('status', 'approved')
                            ->where('business_id', $record->business_id)
                            ->where('id', '!=', $record->id)
                            ->whereHas('customer.customerServiceLevel', fn ($query) => $query->where('priority_order', '<', $currentPriority))
                            ->whereHas('items', fn ($query) => $query->whereIn('product_id', $productIdsInThisOrder))
                            ->get();

                        if ($higherPriorityOrders->isNotEmpty()) {
                            $orderNumbers = $higherPriorityOrders->pluck('so_number')->implode(', ');
                            throw ValidationException::withMessages(['priority' => "Cannot proceed. Higher priority orders ({$orderNumbers}) are waiting for the same stock."]);
                        }

                        // Cek Min SLED Requirement Customer
                        $minSledDate = null;
                        if ($record->customer && $record->customer->min_sled_days > 0) {
                            $minSledDate = now()->addDays($record->customer->min_sled_days);
                        }

                        // 2. Setup Variable
                        $sourceWarehouseId = $data['source_warehouse_id'];
                        $sellableLocationIds = Location::where('locatable_type', Warehouse::class)
                                ->where('locatable_id', $sourceWarehouseId)
                                ->where('is_sellable', true)
                                ->where('status', true)
                                ->where('ownership_type', 'owned')
                                ->pluck('id')->toArray();

                        if (empty($sellableLocationIds)) {
                            throw ValidationException::withMessages(['error' => "No active, sellable, 'owned' stock locations found in the selected warehouse."]);
                        }

                        // 3. Init Strategy Service
                        $strategyService = new PutawayStrategyService();

                        // 4. Create Header Picking List
                        $pickingList = $record->pickingLists()->create([
                            'picking_list_number' => 'PL-SO-' . date('Ym') . '-' . random_int(1000, 9999),
                            'user_id' => $data['assigned_user_id'],
                            'status' => 'pending',
                            'warehouse_id' => $sourceWarehouseId,
                            'business_id' => $record->business_id,
                        ]);

                        // Kirim Notifikasi
                        $picker = User::find($data['assigned_user_id']);
                        if ($picker) {
                            $picker->notify(new \App\Notifications\TaskAssignedNotification(
                                'Picking',
                                $pickingList->picking_list_number,
                                $pickingList->id
                            ));
                        }

                        // 5. Loop Item & Alokasi Stok
                        foreach ($record->items as $item) {
                            $uom = $item->product?->uoms->where('uom_name', $item->uom)->first();
                            $totalQtyToPick = $item->quantity * ($uom?->conversion_rate ?? 1);

                            if ($totalQtyToPick <= 0) continue;

                            $pickingListItem = $pickingList->items()->create([
                                'product_id' => $item->product_id,
                                'total_quantity_to_pick' => $totalQtyToPick,
                                'uom' => $item->product->base_uom
                            ]);

                            // --- STRATEGI DINAMIS ---
                            $targetZoneIds = $strategyService->getPickingZonePriorities($item->product);

                            // Query Dasar Inventory (Sellable & Owned)
                            $inventoryQueryBase = Inventory::whereIn('location_id', $sellableLocationIds)
                                ->where('product_id', $item->product_id)
                                ->where('avail_stock', '>', 0.0001);

                            if ($minSledDate) {
                                $inventoryQueryBase->whereDate('sled', '>=', $minSledDate);
                            }

                            $allocatedQty = 0;
                            $remainingToPick = $totalQtyToPick;

                            // Strategi 1: Cari di Zona Prioritas
                            foreach ($targetZoneIds as $zoneId) {
                                if ($remainingToPick <= 0.0001) break;

                                $inventoriesInZone = (clone $inventoryQueryBase)
                                                ->whereHas('location', fn($q) => $q->where('zone_id', $zoneId))
                                                ->orderBy('sled', 'asc') // FEFO
                                                ->orderBy('id', 'asc')
                                                ->get();

                                foreach ($inventoriesInZone as $inventory) {
                                    if ($remainingToPick <= 0.0001) break;

                                    // [FIX] Cek Stok yang sudah di-reserve oleh Picking List lain
                                    // Hitung jumlah yang sedang di-booking di Picking List yang belum completed/cancelled
                                    $reservedQty = PickingListItemSource::where('inventory_id', $inventory->id)
                                        ->whereHas('item.pickingList', function($q) {
                                            $q->whereIn('status', ['pending', 'in_progress', 'processing']);
                                        })
                                        ->sum('quantity_to_pick_from_source');

                                    // Stok Efektif = Fisik - Reserved
                                    $effectiveStock = max(0, $inventory->avail_stock - $reservedQty);

                                    if ($effectiveStock <= 0.0001) continue; // Skip jika sudah habis di-booking

                                    $qtyFromThisBatch = min($remainingToPick, (float)$effectiveStock);

                                    $pickingListItem->sources()->create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_to_pick_from_source' => $qtyFromThisBatch
                                    ]);

                                    $remainingToPick -= $qtyFromThisBatch;
                                    $allocatedQty += $qtyFromThisBatch;
                                }
                            }

                            // Strategi 2: Safety Net (Cari di zona lain yang belum dicek)
                            if ($remainingToPick > 0.0001) {
                                $otherInventories = (clone $inventoryQueryBase)
                                    ->whereHas('location', fn($q) => $q->whereNotIn('zone_id', $targetZoneIds))
                                    ->orderBy('sled', 'asc')
                                    ->get();

                                foreach ($otherInventories as $inventory) {
                                    if ($remainingToPick <= 0.0001) break;

                                    // [FIX] Cek Reserved Stock (Logic Sama)
                                    $reservedQty = PickingListItemSource::where('inventory_id', $inventory->id)
                                        ->whereHas('item.pickingList', function($q) {
                                            $q->whereIn('status', ['pending', 'in_progress', 'processing']);
                                        })
                                        ->sum('quantity_to_pick_from_source');

                                    $effectiveStock = max(0, $inventory->avail_stock - $reservedQty);

                                    if ($effectiveStock <= 0.0001) continue;

                                    $qtyFromThisBatch = min($remainingToPick, (float)$effectiveStock);

                                    $pickingListItem->sources()->create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_to_pick_from_source' => $qtyFromThisBatch
                                    ]);

                                    $remainingToPick -= $qtyFromThisBatch;
                                    $allocatedQty += $qtyFromThisBatch;
                                }
                            }

                            // Cek Akhir
                            if (round($allocatedQty, 5) < round($totalQtyToPick, 5)) {
                                 $errMsg = $minSledDate
                                           ? "Insufficient 'owned' stock for '{$item->product->name}' (SLED >= {$minSledDate->format('d/m/Y')})."
                                           : "Insufficient 'owned' stock for '{$item->product->name}'. Need: $totalQtyToPick, Found: $allocatedQty.";
                                 throw ValidationException::withMessages(['error' => $errMsg]);
                            }
                        }

                        $record->update(['status' => 'processing']);
                    });

                    Notification::make()->title('Picking list generated successfully!')->success()->send();

                } catch (ValidationException $e) {
                    Notification::make()->title('Failed to generate picking list')->body($e->getMessage())->danger()->send();
                } catch (\Exception $e) {
                     Log::error("GeneratePickingList (SO) Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                     Notification::make()->title('Error')->body('An unexpected error occurred: '.$e->getMessage())->danger()->send();
                }
            });
    }

    // public static function getGeneratePickingListAction(): Tables\Actions\Action
    // {
    //     return Tables\Actions\Action::make('generatePickingList')
    //         ->label('Generate Picking List')
    //         ->icon('heroicon-o-list-bullet')
    //         ->color('info')
    //         ->requiresConfirmation()
    //         ->visible(fn (SalesOrder $record) =>
    //             $record->status === 'approved' &&
    //             // Asumsi Anda punya helper permission
    //             self::userHasPermission('create picking list')
    //         )
    //         ->form([
    //             Forms\Components\Select::make('source_plant_id')
    //                 ->label('Source Plant')
    //                 ->options(function (SalesOrder $record): array {
    //                      $record->loadMissing('supplyingPlant'); // Muat relasi baru
    //                      if ($record->supplyingPlant) {
    //                          return [$record->supplyingPlant->id => $record->supplyingPlant->name];
    //                      }
    //                      // Fallback (seharusnya tidak terjadi jika form 'create' sudah benar)
    //                     return Plant::where('business_id', Auth::user()->business_id)
    //                            ->whereIn('type', ['DISTRIBUTION'])
    //                            ->pluck('name', 'id')->toArray();
    //                 })
    //                 ->default(function(SalesOrder $record) {
    //                      // Ambil dari SO
    //                      return $record->supplying_plant_id;
    //                 })
    //                 ->required()
    //                 ->disabled() // <-- Dikunci, karena sudah ditentukan oleh SO
    //                 ->live(),

    //             Forms\Components\Select::make('source_warehouse_id')
    //                 ->label('Pick Items From Warehouse')
    //                 ->options(function (Get $get, SalesOrder $record): array {
    //                     $plantId = $get('source_plant_id');
    //                     if (!$plantId) return [];

    //                     // (Logika tipe produk Anda sudah benar)
    //                     $productTypes = $record->items()->with('product:id,product_type')->get()
    //                                 ->pluck('product.product_type')->filter()->unique()->values();
    //                     $warehouseTypes = $productTypes->map(function ($productType) {
    //                         $map = [
    //                             'finished_good' => ['FINISHED_GOOD', 'DISTRIBUTION', 'MERCHANDISE'],
    //                             'raw_material' => ['RAW_MATERIAL', 'COLD_STORAGE'],
    //                         ];
    //                         return $map[$productType] ?? ['MAIN', 'OTHER', 'GENERAL'];
    //                     })->flatten()->unique()->all();

    //                     return Warehouse::where('plant_id', $plantId)
    //                         ->whereIn('type', $warehouseTypes)
    //                         ->where('status', true)
    //                         ->pluck('name', 'id')
    //                         ->toArray();
    //                 })
    //                 ->required()
    //                 ->searchable()
    //                 ->live()
    //                 ->helperText('Pilih gudang spesifik di plant sumber untuk picking.'),

    //             Forms\Components\Select::make('assigned_user_id')
    //                 ->label('Assign Picking Task To')
    //                 ->options(function (Get $get): array {
    //                     $sourceWarehouseId = $get('source_warehouse_id');
    //                     if (!$sourceWarehouseId) return [];

    //                     // (Logika filter user Anda sudah benar)
    //                     return User::where('locationable_type', Warehouse::class)
    //                                 ->where('locationable_id', $sourceWarehouseId)
    //                                 ->where('status', true)
    //                                 ->whereHas('position', fn ($q) => $q->whereIn('name', ['Staff Gudang', 'Manager Gudang']))
    //                                 ->pluck('name', 'id')
    //                                 ->toArray();
    //                 })
    //                 ->preload()
    //                 ->required()
    //                 ->searchable()
    //                 ->helperText('Hanya menampilkan staf yang ditugaskan di Warehouse yang dipilih.'),
    //         ])
    //         ->action(function (SalesOrder $record, array $data) {
    //             try {
    //                 DB::transaction(function () use ($record, $data) {
    //                     if ($record->pickingLists()->where('status', '!=', 'cancelled')->exists()) {
    //                         throw ValidationException::withMessages(['error' => 'An active picking list already exists.']);
    //                     }
    //                     $record->loadMissing('customer.customerServiceLevel', 'items.product.uoms');
    //                     $currentCustomerCSL = $record->customer?->customerServiceLevel;
    //                     $currentPriority = $currentCustomerCSL ? $currentCustomerCSL->priority_order : 999;
    //                     $productIdsInThisOrder = $record->items->pluck('product_id');
    //                     $higherPriorityOrders = SalesOrder::where('status', 'approved')
    //                         ->where('business_id', $record->business_id)
    //                         ->where('id', '!=', $record->id)
    //                         ->whereHas('customer.customerServiceLevel', fn ($query) => $query->where('priority_order', '<', $currentPriority))
    //                         ->whereHas('items', fn ($query) => $query->whereIn('product_id', $productIdsInThisOrder))
    //                         ->get();
    //                     if ($higherPriorityOrders->isNotEmpty()) {
    //                         $orderNumbers = $higherPriorityOrders->pluck('so_number')->implode(', ');
    //                         throw ValidationException::withMessages(['priority' => "Cannot proceed. Higher priority orders ({$orderNumbers}) are waiting for the same stock."]);
    //                     }
    //                     $minSledDate = null;
    //                     if ($record->customer && $record->customer->min_sled_days > 0) {
    //                         $minSledDate = now()->addDays($record->customer->min_sled_days);
    //                     }

    //                     $sourceWarehouseId = $data['source_warehouse_id'];
    //                     $zones = Zone::pluck('id', 'code')->all();
    //                     $generalZoneId = $zones['GEN'] ?? null;
    //                     $sellableLocationIds = Location::where('locatable_type', Warehouse::class)
    //                             ->where('locatable_id', $sourceWarehouseId)
    //                             ->where('is_sellable', true)
    //                             ->where('status', true)
    //                             ->where('ownership_type', 'owned')
    //                             ->pluck('id')->toArray();
    //                     if (empty($sellableLocationIds)) {
    //                         throw ValidationException::withMessages(['error' => "No active, sellable, 'owned' stock locations found in the selected warehouse."]);
    //                     }
    //                     $pickingList = $record->pickingLists()->create([
    //                         'picking_list_number' => 'PL-SO-' . date('Ym') . '-' . random_int(1000, 9999),
    //                         'user_id' => $data['assigned_user_id'],
    //                         'status' => 'pending',
    //                         'warehouse_id' => $sourceWarehouseId,
    //                         'business_id' => $record->business_id,
    //                     ]);

    //                     $picker = User::find($data['assigned_user_id']);

    //                     if ($picker) {
    //                         // Menggunakan Notification Class yang sama dengan Putaway
    //                         $picker->notify(new \App\Notifications\TaskAssignedNotification(
    //                             'Picking',                          // Tipe Tugas (Case Sensitive untuk Title, tapi di lowercase di logic payload)
    //                             $pickingList->picking_list_number,  // Nomor Dokumen
    //                             $pickingList->id                    // ID Referensi (untuk navigasi)
    //                         ));
    //                     }

    //                     foreach ($record->items as $item) {
    //                         $uom = $item->product?->uoms->where('uom_name', $item->uom)->first();
    //                         $totalQtyToPick = $item->quantity * ($uom?->conversion_rate ?? 1);
    //                         if ($totalQtyToPick <= 0) continue;
    //                         $pickingListItem = $pickingList->items()->create([
    //                             'product_id' => $item->product_id,
    //                             'total_quantity_to_pick' => $totalQtyToPick,
    //                             'uom' => $item->product->base_uom
    //                         ]);
    //                         $productType = $item->product?->product_type;
    //                         $storageCondition = $item->product?->storage_condition;
    //                         $zonePriorityMap = [
    //                             'finished_good' => ['FAST', 'LINE-A', 'FG', 'GEN'],
    //                             'merchandise' => ['MCH', 'FAST', 'GEN'],
    //                             'raw_material' => ['RM', 'COLD', 'GEN'],
    //                         ];
    //                         $defaultPriority = ['GEN'];
    //                         if ($storageCondition === 'COLD' && isset($zones['COLD'])) $priorityCodes = ['COLD', 'GEN'];
    //                         else $priorityCodes = $zonePriorityMap[$productType] ?? $defaultPriority;
    //                         $zonePriorityOrder = collect($priorityCodes)->map(fn($c) => $zones[strtoupper($c)] ?? null)->filter()->unique()->all();
    //                         $inventoryQueryBase = Inventory::whereIn('location_id', $sellableLocationIds)
    //                             ->where('product_id', $item->product_id)->where('avail_stock', '>', 0);
    //                         if ($minSledDate) $inventoryQueryBase->whereDate('sled', '>=', $minSledDate);
    //                         $allocatedQty = 0;
    //                         $remainingToPick = $totalQtyToPick;
    //                         foreach ($zonePriorityOrder as $zoneId) {
    //                             if ($remainingToPick <= 0) break;
    //                             $inventoriesInZone = (clone $inventoryQueryBase)
    //                                             ->whereHas('location', fn($q) => $q->where('zone_id', $zoneId))
    //                                             ->orderBy('sled', 'asc')->get();
    //                             foreach ($inventoriesInZone as $inventory) {
    //                                 if ($remainingToPick <= 0) break;
    //                                 $qtyFromThisBatch = min($remainingToPick, $inventory->avail_stock);
    //                                 $pickingListItem->sources()->create([
    //                                     'inventory_id' => $inventory->id,
    //                                     'quantity_to_pick_from_source' => $qtyFromThisBatch
    //                                 ]);
    //                                 $remainingToPick -= $qtyFromThisBatch;
    //                                 $allocatedQty += $qtyFromThisBatch;
    //                             }
    //                         }
    //                         if (round($allocatedQty, 5) < round($totalQtyToPick, 5)) {
    //                              $errMsg = $minSledDate
    //                                        ? "Insufficient 'owned' stock for '{$item->product->name}' (SLED >= {$minSledDate->format('d/m/Y')})."
    //                                        : "Insufficient 'owned' stock for '{$item->product->name}'.";
    //                              throw ValidationException::withMessages(['error' => $errMsg]);
    //                         }
    //                     }
    //                     $record->update(['status' => 'processing']);
    //                 });
    //                 Notification::make()->title('Picking list generated successfully!')->success()->send();
    //             } catch (ValidationException $e) {
    //                 Notification::make()->title('Failed to generate picking list')->body($e->getMessage())->danger()->send();
    //             } catch (\Exception $e) {
    //                  Log::error("GeneratePickingList (SO) Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    //                  Notification::make()->title('Error')->body('An unexpected error occurred: '.$e->getMessage())->danger()->send();
    //             }
    //         });
    // }

public static function getGoToWorkbenchAction(): Tables\Actions\Action
    {
         return Tables\Actions\Action::make('goToWorkbench')
            ->label('View in Shipment Workbench')
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->url(ShipmentWorkbenchResource::getUrl('index'))
            ->visible(fn (SalesOrder $record) => $record->status === 'ready_to_ship');
    }


    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
            RelationManagers\ShipmentsRelationManager::class,
            RelationManagers\PickingListsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesOrders::route('/'),
            'create' => Pages\CreateSalesOrder::route('/create'),
            'edit' => Pages\EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
