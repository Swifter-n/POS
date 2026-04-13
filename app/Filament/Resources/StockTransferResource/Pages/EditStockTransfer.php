<?php

namespace App\Filament\Resources\StockTransferResource\Pages;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use App\Filament\Resources\ShipmentResource;
use App\Filament\Resources\StockTransferResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\ShipmentRoute;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\PutawayStrategyService;
use App\Traits\HasPermissionChecks;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditStockTransfer extends EditRecord
{
    use HasPermissionChecks;
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Cek jika ini transfer internal (berdasarkan data dari DB)
        if (isset($data['transfer_type']) && $data['transfer_type'] === 'internal' && isset($data['plant_id'])) {

            // "Suntikkan" nilai plant_id (dari DB) ke field form 'internal_plant_id'
            // Ini akan membuat field 'internal_plant_id' memiliki nilai yang benar
            // saat form pertama kali dimuat.
            $data['internal_plant_id'] = $data['plant_id'];
        }

        return $data;
    }


   public function isInternalTransfer(): bool
    {
        $record = $this->getRecord();

        // 1. Cek berdasarkan 'transfer_type' (jika sudah ada)
        if ($record->transfer_type === 'internal') return true;
        if (str_starts_with($record->transfer_type, 'external')) return false;

        // 2. Fallback (jika data lama belum ada transfer_type)
        if ($record->source_location_id && $record->destination_location_id) {
             $record->loadMissing(['sourceLocation.locatable', 'destinationLocation.locatable']);
             if (!$record->sourceLocation?->locatable || !$record->destinationLocation?->locatable) return false;
             return $record->sourceLocation->locatable->is($record->destinationLocation->locatable);
        }
        if ($record->source_plant_id && $record->destination_plant_id) {
            return false; // Eksternal
        }
        return false;
    }

    /**
     * Form Header (Kondisional berdasarkan Status)
     */
    public function form(Form $form): Form
    {
        $record = $this->getRecord();
        // ==========================================================
        // --- LOGIKA EDIT DISEMPURNAKAN ---
        // ==========================================================
        $isEditable = $record->status === 'draft'; // Hanya draft STO/Sortir

        $schema = [
            Section::make('Transfer Details')
                ->schema(
                    array_merge(
                        [
                            // Field ini selalu Placeholder
                            Placeholder::make('transfer_number')
                                ->content($record->transfer_number),
                            Placeholder::make('status')
                                ->content(fn() => ucwords(str_replace('_', ' ', $record->status))),

                            Placeholder::make('transfer_type')
                                ->label('Transfer Type')
                                ->content(function () use ($record) { // Hapus $isPutAway
                                    // 1. Baca dari kolom transfer_type (lebih akurat)
                                    if ($record->transfer_type === 'internal') return 'Internal (Location to Location)';
                                    if ($record->transfer_type === 'external_plant') return 'External (Plant to Plant)';
                                    if ($record->transfer_type === 'external_outlet') return 'External (Plant to Outlet)';
                                    // 2. Fallback (jika data lama)
                                    if ($record->source_plant_id && $record->destination_plant_id) return 'External (Plant to Plant)';
                                    if ($record->source_plant_id && $record->destination_outlet_id) return 'External (Plant to Outlet)';
                                    if ($record->source_location_id && $record->destination_location_id) return 'Internal (Location to Location)';
                                    return 'Unknown'; // Fallback
                                }),
                        ],
                        // Field Dinamis (Placeholder atau Input)
                        $isEditable ? $this->getEditableSchema($record) : $this->getReadOnlySchema($record),
                        [
                            // Field Notes (selalu di akhir, kondisional)
                            $isEditable
                                ? Textarea::make('notes')->columnSpanFull()
                                : Placeholder::make('notes')->content($record->notes),
                        ]
                    )
                )->columns(2),
        ];

        return $form->schema($schema);
    }

    /**
     * Helper untuk mendapatkan schema field yang BISA DIEDIT (untuk STO Draft)
     * (Logika ini sudah ideal)
     */
    protected function getEditableSchema(StockTransfer $record): array
    {
        return [
             Select::make('transfer_type')
                ->label('Select Transfer Type')
                ->options([
                    'internal' => 'Internal (Within same Plant/WH)',
                    'external_plant' => 'External (Plant to Plant/DC)',
                    'external_outlet' => 'External (Plant to Outlet)',
                ])
                ->required()
                ->live()
                // ==========================================================
                // --- LOGIKA LENGKAP: default() ---
                // ==========================================================
                ->default(function() use ($record) {
                    if ($record->transfer_type) return $record->transfer_type;
                    if ($record->source_plant_id && $record->destination_plant_id) return 'external_plant';
                    if ($record->source_plant_id && $record->destination_outlet_id) return 'external_outlet';
                    if ($record->source_location_id && $record->destination_location_id) return 'internal';
                    return 'external_plant'; // Fallback default
                })
                // ==========================================================
                // --- LOGIKA LENGKAP: afterStateUpdated() ---
                // ==========================================================
                ->afterStateUpdated(function(Set $set){
                    $set('internal_plant_id', null);
                    $set('source_location_id', null);
                    $set('destination_location_id', null);
                    $set('source_plant_id', null);
                    $set('destination_plant_id', null);
                    $set('destination_outlet_id', null);
                }),
            // --- Opsi untuk Internal Transfer ---
            Select::make('internal_plant_id')
                ->label('Select Plant (for Internal Transfer)')
                ->options(fn() => Plant::where('business_id', Auth::user()->business_id)->where('status', true)->pluck('name', 'id'))
                ->live()
                ->searchable()
                ->required(fn (Get $get) => $get('transfer_type') === 'internal')
                ->visible(fn (Get $get) => $get('transfer_type') === 'internal'),

            Select::make('source_location_id')
                ->label('Source Location (Internal)')
                ->options(function (Get $get) {
                    $plantId = $get('internal_plant_id');
                    if (!$plantId) return [];
                    return Location::whereHasMorph(
                        'locatable', [Warehouse::class, Outlet::class],
                        function(Builder $q, string $type) use ($plantId) {
                            if ($type === Warehouse::class) $q->where('plant_id', $plantId);
                            elseif ($type === Outlet::class) $q->where('supplying_plant_id', $plantId);
                        }
                    )
                    // Pastikan semua tipe sumber area transien/penyimpanan ada di sini
                    ->whereIn('type', ['AREA', 'STAGING', 'QI'])
                    ->where('status', true)
                    ->get()
                    // Tampilkan Zona agar jelas (STG/QI/AFS)
                    ->mapWithKeys(fn($loc) => [$loc->id => ($loc->locatable?->name ?? '') . ' > '. ($loc->zone?->code ?? 'N/A') .'> ' . $loc->name]);
                })
                ->searchable()->preload()->live()
                ->required(fn (Get $get) => $get('transfer_type') === 'internal')
                ->visible(fn (Get $get) => $get('transfer_type') === 'internal'),

             Select::make('destination_location_id')
                ->label('Destination Location (Internal)')
                 ->options(function (Get $get) {
                    $plantId = $get('internal_plant_id');
                    $sourceLocId = $get('source_location_id');
                    if (!$plantId) return [];
                    $query = Location::whereHasMorph(
                        'locatable', [Warehouse::class, Outlet::class],
                        function(Builder $q, string $type) use ($plantId) {
                            if ($type === Warehouse::class) $q->where('plant_id', $plantId);
                            elseif ($type === Outlet::class) $q->where('supplying_plant_id', $plantId);
                        }
                    )
                    ->where('is_sellable', false)
                    ->whereIn('type', ['AREA']) // (Tujuan tetap AREA)
                    ->where('status', true);
                    if ($sourceLocId) $query->where('id', '!=', $sourceLocId);
                    // Tampilkan Zona agar jelas
                    return $query->get()->mapWithKeys(fn($loc) => [$loc->id => ($loc->locatable?->name ?? '') . ' > '. ($loc->zone?->code ?? 'N/A') .'> ' . $loc->name]);
                })
                ->searchable()->preload()
                // ==========================================================
                // --- 'required' kondisional (Logika ini sudah benar) ---
                // ==========================================================
                ->required(function (Get $get): bool {
                    if ($get('transfer_type') !== 'internal') return false;
                    $sourceLoc = Location::find($get('source_location_id'));
                    if (!$sourceLoc) return true;
                    $sourceLoc->loadMissing('zone');
                    // TIDAK wajib jika sumbernya QI atau STG
                    return !in_array($sourceLoc?->zone?->code, ['QI', 'STG']);
                })
                ->visible(fn (Get $get) => $get('transfer_type') === 'internal'),

            // --- Opsi untuk External Transfer (Plant/Outlet) ---
             Select::make('source_plant_id')
                ->label('Source Plant')
                // ==========================================================
                // --- LOGIKA LENGKAP: options() ---
                // ==========================================================
                ->options(fn() => Plant::where('business_id', Auth::user()->business_id)->where('status', true)->pluck('name', 'id'))
                ->searchable()->preload()->live()
                ->required(fn (Get $get) => str_starts_with($get('transfer_type'), 'external'))
                ->visible(fn (Get $get) => str_starts_with($get('transfer_type'), 'external')),
            Select::make('destination_plant_id')
                ->label('Destination Plant/DC')
                // ==========================================================
                // --- LOGIKA LENGKAP: options() ---
                // ==========================================================
                 ->options(function (Get $get) {
                    $sourcePlantId = $get('source_plant_id');
                    $query = Plant::where('business_id', Auth::user()->business_id)->where('status', true);
                    if ($sourcePlantId) $query->where('id', '!=', $sourcePlantId);
                    return $query->pluck('name', 'id');
                 })
                ->searchable()->preload()
                ->required(fn (Get $get) => $get('transfer_type') === 'external_plant')
                ->visible(fn (Get $get) => $get('transfer_type') === 'external_plant'),

                Select::make('destination_outlet_id')
                ->label('Destination Outlet (Internal)')
                ->options(function (Get $get) {
                    $query = Outlet::where('business_id', Auth::user()->business_id)
                                    ->where('status', true)
                                    // [PERBAIKAN] Hanya tampilkan Outlet Internal
                                    ->where('ownership_type', 'internal');
                    return $query->pluck('name', 'id');
                })
                ->helperText('Hanya menampilkan outlet internal. Outlet franchise harus via Sales Order.')
                ->searchable()->preload()
                ->required(fn (Get $get) => $get('transfer_type') === 'external_outlet')
                ->visible(fn (Get $get) => $get('transfer_type') === 'external_outlet'),

            DatePicker::make('request_date')
                ->required()
                ->default($record->request_date ?? now()),
        ];
    }

     /**
      * Helper untuk mendapatkan schema field READ-ONLY (Placeholder)
      */
     protected function getReadOnlySchema(StockTransfer $record): array
     {
         // ==========================================================
         // --- HAPUS 'isPutAway' DARI SINI ---
         // ==========================================================

         if ($this->isInternalTransfer()) {
              $record->loadMissing(['sourceLocation.locatable', 'destinationLocation.locatable']);
         } else {
             $record->loadMissing(['sourcePlant', 'destinationPlant', 'destinationOutlet']);
         }

         return [
             Placeholder::make('source_display')
                 ->label('Source (From)')
                 ->content(function() use ($record) {
                     if ($record->sourcePlant) return $record->sourcePlant->name . ' (Plant)';
                     if ($record->sourceLocation) return ($record->sourceLocation->locatable?->name ?? '') . ' > ' . $record->sourceLocation->name;
                     return 'N/A';
                 }),
             Placeholder::make('destination_display')
                 ->label('Destination (To)')
                 ->content(function () use ($record) { // Hapus $isPutAway
                     if ($record->destinationPlant) return $record->destinationPlant->name . ' (Plant)';
                     if ($record->destinationOutlet) return $record->destinationOutlet->name . ' (Outlet)';
                     if ($record->destinationLocation) return ($record->destinationLocation->locatable?->name ?? '') . ' > ' . $record->destinationLocation->name;

                     // --- HAPUS BLOK 'if ($isPutAway)' ---

                     return 'N/A';
                 }),
             Placeholder::make('request_date')
                 ->content($record->request_date?->format('d M Y')),
         ];
     }

    /**
     * Tampilkan tombol Save/Cancel (karena ini bukan PutAway)
     */
    protected function getFormActions(): array
    {
        return [];
        //return parent::getFormActions();
    }


    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();

        // Panggil helper di sini agar bisa digunakan di semua 'visible'
        $isInternal = $this->isInternalTransfer();
        $record->loadMissing('sourceLocation.zone');
        $sourceZoneCode = $record->sourceLocation?->zone?->code;
        $record->loadMissing('destinationLocation.zone');
        $destinationZoneCode = $record->destinationLocation?->zone?->code;
        $transientZones = ['QI', 'STG', 'RCV', 'DMG'];

        return [

            //AKSI 1: EXECUTE INTERNAL TRANSFER (Untuk Sortir, QI -> AFS, dll)
            Actions\Action::make('executeInternalTransfer')
                ->label('Execute Internal Transfer (Sortir)')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                // ==========================================================
                // --- PERBAIKAN LOGIKA VISIBLE (Logika Baru) ---
                // Tampilkan jika DRAFT, INTERNAL, dan:
                // 1. Tujuan DIISI
                // 2. Sumber adalah Area Transien (QI, STG, RCV, DMG)
                // 3. Tujuan adalah Area Transien (QI, STG, RCV, DMG)
                // ==========================================================
                ->visible(fn () =>
                    $record->status === 'draft' &&
                    $isInternal &&
                    $record->destination_location_id !== null && // Kunci 1: Tujuan DIISI
                    in_array($sourceZoneCode, $transientZones) && // Kunci 2: Sumber = Transien
                    in_array($destinationZoneCode, $transientZones) && // Kunci 3: Tujuan = Transien
                    $this->check($user, 'execute internal transfers')
                )
                ->action(function (StockTransfer $record) {
                    try {
                        DB::transaction(function () use ($record) {
                             if (empty($record->destination_location_id)) {
                                 throw new \Exception("Destination Location is required for 'Execute' action.");
                             }
                             $unconfirmedItems = $record->items()->whereNull('quantity_picked')->count();
                            if ($unconfirmedItems > 0) {
                                throw new \Exception("Terdapat {$unconfirmedItems} item yang belum di-input Actual Qty Moved.");
                            }
                            $record->update(['status' => 'in_progress', 'started_at' => now()]);
                            $record->load('items.product', 'sourceLocation', 'destinationLocation');
                            foreach($record->items as $item) {
                                $quantityInBaseUom = (int)$item->quantity_picked;
                                if ($quantityInBaseUom <= 0) continue;
                                $inventories = Inventory::where('location_id', $record->source_location_id)
                                    ->where('product_id', $item->product_id)
                                    ->where('avail_stock', '>', 0)
                                    ->orderBy('sled', 'asc')
                                    ->get();
                                if ($inventories->sum('avail_stock') < $quantityInBaseUom) {
                                    throw new \Exception("Insufficient stock for '{$item->product->name}' at the source location.");
                                }
                                $remainingToMove = $quantityInBaseUom;
                                foreach ($inventories as $inventory) {
                                    if ($remainingToMove <= 0) break;
                                    $qtyFromThisBatch = min($remainingToMove, $inventory->avail_stock);
                                    $inventory->decrement('avail_stock', $qtyFromThisBatch);
                                    InventoryMovement::create([
                                        'inventory_id' => $inventory->id, 'quantity_change' => -$qtyFromThisBatch,
                                        'stock_after_move' => $inventory->avail_stock, 'type' => 'TRANSFER_OUT_INTERNAL',
                                        'reference_type' => get_class($record), 'reference_id' => $record->id, 'user_id' => Auth::id(),
                                        'notes' => "Internal transfer from {$record->sourceLocation?->name}",
                                    ]);
                                    $destinationInventory = Inventory::firstOrCreate(
                                        ['location_id' => $record->destination_location_id, 'product_id' => $inventory->product_id, 'batch' => $inventory->batch],
                                        ['sled' => $inventory->sled, 'avail_stock' => 0, 'business_id' => $record->business_id]
                                    );
                                    $destinationInventory->increment('avail_stock', $qtyFromThisBatch);
                                    InventoryMovement::create([
                                        'inventory_id' => $destinationInventory->id, 'quantity_change' => $qtyFromThisBatch,
                                        'stock_after_move' => $destinationInventory->avail_stock, 'type' => 'TRANSFER_IN_INTERNAL',
                                        'reference_type' => get_class($record), 'reference_id' => $record->id, 'user_id' => Auth::id(),
                                        'notes' => "Internal transfer to {$record->destinationLocation?->name}",
                                    ]);
                                    $remainingToMove -= $qtyFromThisBatch;
                                }
                            }
                            $record->update(['status' => 'completed', 'completed_at' => now()]);
                        });
                        Notification::make()->title('Internal transfer executed successfully!')->body('Stock has been moved based on actual quantity.')->success()->send();
                    } catch (\Exception $e) {
                         if ($record->status === 'in_progress') {
                             $record->update(['status' => 'draft']);
                         }
                        Notification::make()->title('Transfer Failed')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

            // ==========================================================
            // --- AKSI 2: CONVERT TO PUT-AWAY TASK (Skenario B) ---
            // ==========================================================
            Actions\Action::make('convertToPutAwayTask')
                ->label('Convert to Put-Away Task')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Convert to Put-Away Task')
                ->modalDescription('This will cancel the current transfer and create a new Put-Away task (PA-...) based on the "Actual Qty Moved". Are you sure?')
                // ==========================================================
                // --- PERBAIKAN LOGIKA VISIBLE (Logika Baru) ---
                // Tampilkan jika DRAFT, INTERNAL, dan:
                // 1. Tujuan KOSONG
                // 2. Sumber adalah Area Transien (QI atau STG)
                // ==========================================================
                ->visible(fn () =>
                    $record->status === 'draft' &&
                    $isInternal &&
                    $record->destination_location_id === null && // <-- Kunci Logika 1
                    in_array($sourceZoneCode, ['QI', 'STG', 'RCV']) && // <-- Kunci Logika 2 (RCV juga bisa)
                    $this->check($user, 'create stock transfers')
                )
                ->action(function (StockTransfer $record) use ($sourceZoneCode) { // <-- PERBAIKAN 1
                    try {
                        DB::transaction(function () use ($record, $sourceZoneCode) { // <-- PERBAIKAN 2
                            $unconfirmedItems = $record->items()->whereNull('quantity_picked')->count();
                            if ($unconfirmedItems > 0) {
                                throw new \Exception("Terdapat {$unconfirmedItems} item yang belum di-input Actual Qty Moved.");
                            }
                            $itemsToMove = $record->items()->where('quantity_picked', '>', 0)->get();
                            if ($itemsToMove->isEmpty()) {
                                 throw new \Exception("No items found with 'Actual Qty Moved' > 0.");
                            }

                            // [BARU] Tentukan prefix nomor berdasarkan Zona Sumber
                            $prefix = 'PA-OTH-'; // Default
                            if ($sourceZoneCode === 'STG') $prefix = 'PA-CANCEL-';
                            if ($sourceZoneCode === 'QI') $prefix = 'PA-QI-';

                            $transferNumber = $prefix . $record->transfer_number;

                            $putAwayTask = StockTransfer::create([
                                'transfer_number' => $transferNumber,
                                'business_id' => $record->business_id,
                                'source_location_id' => $record->source_location_id, // Lokasi STG/QI
                                'destination_location_id' => null, // <-- KOSONG
                                'status' => 'draft',
                                'notes' => 'Tugas put-away (Sortir/Batal) dari STO #' . $record->transfer_number,
                                'requested_by_user_id' => Auth::id(),
                                'request_date' => now(),
                                'sourceable_type' => get_class($record),
                                'sourceable_id' => $record->id,
                                'plant_id' => $record->plant_id,
                                'from_warehouse_id' => $record->sourceLocation?->locatable_id,
                                'transfer_type' => 'put_away', // <-- FLAG PENTING
                            ]);

                            // Salin item (berdasarkan quantity_picked)
                            foreach ($itemsToMove as $item) {
                                $product = Product::find($item->product_id);
                                $baseUomName = $product?->base_uom ?? 'PCS';
                                $putAwayTask->items()->create([
                                    'product_id' => $item->product_id,
                                    'quantity' => $item->quantity_picked, // Qty Aktual (Base)
                                    'uom' => $baseUomName, // UoM Aktual (Base)
                                ]);
                            }
                            $record->update(['status' => 'cancelled']);
                        });
                        Notification::make()->title('Put-Away Task Created!')->body('Tugas Put-Away berhasil dibuat. STO ini dibatalkan.')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (\Exception $e) {
                        Notification::make()->title('Failed to Create Task')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

                    /**
         * AKSI 2: SUBMIT FOR APPROVAL (Untuk transfer eksternal W->O atau O->W)
         */
        Actions\Action::make('submitForApproval')
                ->label('Submit for Approval')
                ->color('info')->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->action(function (StockTransfer $record) {
                    // Validasi: Pastikan Plant Sumber & Tujuan (Plant/Outlet) sudah terisi
                    // Perbarui field check sesuai schema baru
                    if (!$record->source_plant_id || (!$record->destination_plant_id && !$record->destination_outlet_id)) {
                        Notification::make()->title('Cannot Submit')->body('Source and Destination Plant/Outlet must be selected.')->warning()->send();
                        return;
                    }
                    if ($record->items()->count() === 0) {
                        Notification::make()->title('Cannot Submit')->body('Please add at least one item.')->warning()->send();
                        return;
                    }
                    $record->update(['status' => 'pending_approval']);
                    Notification::make()->title('Request submitted for approval.')->success()->send();
                })
                 // Visible jika draft, BUKAN internal, BUKAN putaway, punya izin
                ->visible(function (StockTransfer $record) use ($user, $isInternal) { // <-- [PERBAIKAN] Ubah ke closure penuh

        // 1. Cek Status (Logika Anda sudah benar)
        if ($record->status !== 'draft' || $isInternal) {
            return false;
        }

        // 2. Cek Permission (Logika Anda sudah benar)
        if (!$this->check($user, 'create stock transfers')) {
            return false;
        }

        // 3. Cek Owner (Boleh submit dari mana saja)
        if ($user->hasRole('Owner')) {
            return true;
        }

        // ==========================================================
        // --- LOGIKA BARU: Cek Lokasi (Plant) ---
        // ==========================================================

        // 4. Ambil plant_id user (dari referensi EmployeeResource p-96)
        $userPlantId = $user->plant_id;

        // 5. Cek: Apakah User ini bertugas di Plant yang sama dengan STO?
        //    (Hanya staf di plant SUMBER yang boleh menekan 'submit')
        return $record->source_plant_id && $userPlantId && $record->source_plant_id === $userPlantId;
    }),

         /** AKSI 2: APPROVE (Untuk Manager/Head/Owner)
         */
        Actions\Action::make('approve')
                ->label('Approve Transfer')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(function (StockTransfer $record) use ($user, $isInternal) { // [PERBAIKAN] Tambahkan $record

        // 1. Cek Status (Logika Anda sudah benar)
        if ($record->status !== 'pending_approval' || $isInternal) return false;

        // 2. Cek Permission (Logika Anda sudah benar)
        if (!$this->check($user, 'approve stock transfers')) return false;

        // 3. Cek Owner (Logika Anda sudah benar)
        if ($user->hasRole('Owner')) return true;

        // ==========================================================
        // --- LOGIKA BARU: Cek Jabatan (Position) ---
        // ==========================================================

        // 4. Muat relasi Jabatan (dari referensi EmployeeResource)
        $user->loadMissing('position');
        $userPositionName = $user->position?->name;

        // 5. Tentukan siapa yang boleh approve
        // (SESUAIKAN NAMA JABATAN INI DENGAN DATA ANDA)
        $approverPositions = ['Manager', 'Head', 'Supervisor', 'Manager Gudang', 'Head Gudang'];

        if (!in_array($userPositionName, $approverPositions)) {
            return false; // Sembunyikan jika BUKAN Manager/Head/etc.
        }

        // ==========================================================
        // --- LOGIKA LOKASI (Menggunakan plant_id Karyawan) ---
        // ==========================================================

        // 6. Ambil plant_id user (dari referensi EmployeeResource)
        $userPlantId = $user->plant_id;

        // 7. Cek: Apakah Manajer ini bertugas di Plant yang sama dengan STO?
        return $record->source_plant_id && $userPlantId && $record->source_plant_id === $userPlantId;
    })
                ->action(function ($record) {
                    try {
                        DB::transaction(function() use ($record) {
                            $sourceLocationId = null;
                            $destinationLocationId = null;
                            $businessId = $record->business_id; // Ambil business_id

                            // --- Tentukan Lokasi Sumber (Staging) ---
                            $stgZone = Zone::where('code', 'STG')->first();
                            if ($stgZone && $record->source_plant_id) {
                                // Cari lokasi STG di SEMUA warehouse dalam plant sumber
                                $sourceLocations = Location::where('zone_id', $stgZone->id)
                                        ->where('status', true) // <-- Pastikan Aktif
                                        ->whereHasMorph('locatable', [Warehouse::class], fn($q) => $q->where('plant_id', $record->source_plant_id))
                                        ->get();

                                // Cari default staging
                                $defaultSource = $sourceLocations->firstWhere('is_default_staging', true);
                                if ($defaultSource) {
                                     $sourceLocationId = $defaultSource->id;
                                     Log::info("Default Staging location found: ID {$sourceLocationId}");
                                } elseif ($sourceLocations->count() === 1) {
                                     // Jika tidak ada default tapi hanya ada 1, gunakan itu
                                     $sourceLocationId = $sourceLocations->first()->id;
                                     Log::info("Only one Staging location found: ID {$sourceLocationId}");
                                } elseif ($sourceLocations->count() > 1) {
                                     // Jika > 1 dan tidak ada default, lempar error
                                     throw new \Exception('Multiple Staging locations (Zone: STG) found and no default is set in source plant. Please set one default.');
                                }
                            }
                            if (!$sourceLocationId) throw new \Exception('Default or single Staging location (Zone: STG) not found/configured in source plant.');

                            // --- Tentukan Lokasi Tujuan (Receiving/Main) ---
                            $destZoneCode = null;
                            $destLocatableQuery = null;
                            $locatableType = null; // Tipe Model Tujuan
                            $destIdentifier = null; // Untuk pesan error

                            if ($record->destination_plant_id) {
                                $destZoneCode = 'RCV'; // Plant tujuan pakai RCV
                                $destLocatableQuery = function($q) use ($record) {
                                    $q->where('plant_id', $record->destination_plant_id);
                                };
                                $locatableType = Warehouse::class;
                                $destIdentifier = "Plant ID {$record->destination_plant_id}";

                            } elseif ($record->destination_outlet_id) {
                                $destZoneCode = 'MAIN'; // Outlet tujuan pakai MAIN
                                $destLocatableQuery = function($q) use ($record) {
                                    $q->where('id', $record->destination_outlet_id);
                                };
                                $locatableType = Outlet::class;
                                $destIdentifier = "Outlet ID {$record->destination_outlet_id}";
                            }

                            if ($destZoneCode && $destLocatableQuery) {
                                $destZone = Zone::where('code', $destZoneCode)->first();
                                if ($destZone) {
                                     // Cari lokasi RCV/MAIN yang AKTIF di Plant/Outlet tujuan
                                     $destinationLocations = Location::where('zone_id', $destZone->id)
                                            ->where('status', true) // <-- Pastikan Aktif
                                            ->whereHasMorph('locatable', [$locatableType], $destLocatableQuery)
                                            ->get();

                                     // Cari default receiving
                                     $defaultDestination = $destinationLocations->firstWhere('is_default_receiving', true);
                                     if ($defaultDestination) {
                                          $destinationLocationId = $defaultDestination->id;
                                          Log::info("Default Receiving/Main location found: ID {$destinationLocationId}");
                                     } elseif ($destinationLocations->count() === 1) {
                                          // Jika tidak ada default tapi hanya ada 1, gunakan itu
                                          $destinationLocationId = $destinationLocations->first()->id;
                                          Log::info("Only one Receiving/Main location found: ID {$destinationLocationId}");
                                     } elseif ($destinationLocations->count() > 1) {
                                          // Jika > 1 dan tidak ada default, lempar error
                                          throw new \Exception("Multiple '{$destZoneCode}' locations found and no default is set in destination {$destIdentifier}. Please set one default.");
                                     }
                                }
                            }
                            if (!$destinationLocationId) throw new \Exception("Default or single '{$destZoneCode}' location not found/configured in destination {$destIdentifier}.");

                            // Update STO dengan lokasi dan status
                            $record->update([
                                'source_location_id' => $sourceLocationId,
                                'destination_location_id' => $destinationLocationId,
                                'status' => 'approved',
                                'approved_by_user_id' => Auth::id()
                            ]);
                        });
                        Notification::make()->title('Transfer has been approved.')->success()->send();
                         // Refresh form untuk menampilkan lokasi yg baru di-set
                        $this->refreshFormData(['source_display', 'destination_display', 'status']);
                    } catch (\Exception $e) {
                         Notification::make()->title('Approval Failed')->body($e->getMessage())->danger()->send();
                         $this->halt();
                    }
                })
                ->visible(function ($record) use ($user, $isInternal) { // Gunakan use
                    if ($record->status !== 'pending_approval' || $isInternal) return false;
                    if (!$this->check($user, 'approve stock transfers')) return false;
                    if ($user->hasRole('Owner')) return true;

                    // Validasi lokasi berdasarkan Plant Sumber
                    $userPlantId = null; // Cek plant user
                     if($user->locationable instanceof Warehouse) $userPlantId = $user->locationable->plant_id;
                    return $record->source_plant_id && $userPlantId && $record->source_plant_id === $userPlantId;
                }),

                // ==========================================================
            // AKSI 5: GENERATE PICKING LIST (BARU)
            // Menggantikan 'prepareShipment'
            // ==========================================================
            Actions\Action::make('generatePickingList')
                ->label('Generate Picking List')
                ->icon('heroicon-o-list-bullet')->color('info')->requiresConfirmation()
                // Visible jika approved, BUKAN internal, BUKAN putaway
                ->visible(function (StockTransfer $record) use ($user, $isInternal) { // <-- [PERBAIKAN] Ubah ke closure penuh

        // 1. Cek Status (Logika Anda sudah benar)
        if ($record->status !== 'approved' || $isInternal) {
            return false;
        }

        // 2. Cek Permission (Logika Anda sudah benar)
        if (!$this->check($user, 'create picking list')) {
            return false;
        }

        // 3. Cek Owner (Boleh generate dari mana saja)
        if ($user->hasRole('Owner')) {
            return true;
        }

        // ==========================================================
        // --- LOGIKA BARU: Cek Lokasi (Plant) ---
        // ==========================================================

        // 4. Ambil plant_id user (dari referensi EmployeeResource p-96)
        $userPlantId = $user->plant_id;

        // 5. Cek: Apakah User ini bertugas di Plant yang sama dengan STO?
        //    (Hanya staf di plant SUMBER yang boleh generate PL)
        return $record->source_plant_id && $userPlantId && $record->source_plant_id === $userPlantId;
    })
                // ==========================================================
                // --- PERBAIKAN: Ubah type-hint 'Model' menjadi 'StockTransfer' ---
                // ==========================================================
                ->form(function (StockTransfer $record) use ($user): array { // <-- PERBAIKAN DI SINI
                    return [ // Kembalikan array schema
                        Select::make('source_warehouse_id')
                            ->label('Pick Items From Warehouse')
                            // Opsi: Gudang di dalam Plant Sumber STO ini
                            ->options(function () use ($record): array { // 'use ($record)' sekarang valid
                                // 1. Dapatkan tipe produk unik dari item STO
                                $productTypes = $record->items()
                                    ->with('product:id,product_type') // Hanya ambil product_type
                                    ->get()
                                    ->pluck('product.product_type')
                                    ->filter() // Hapus null
                                    ->unique()
                                    ->values();

                                // 2. Map product_type ke warehouse_type
                                $warehouseTypes = $productTypes->map(function ($productType) {
                                    // Map ini MENGASUMSIKAN tipe warehouse Anda
                                    $map = [
                                        'raw_material' => ['RAW_MATERIAL', 'COLD_STORAGE'],
                                        'finished_good' => ['FINISHED_GOOD', 'DISTRIBUTION'],
                                        'merchandise' => ['FINISHED_GOOD', 'DISTRIBUTION', 'MERCHANDISE'],
                                    ];
                                    // Fallback jika tipe produk tidak ada di map
                                    return $map[$productType] ?? ['MAIN', 'OTHER', 'GENERAL']; // Tambahkan GEN
                                })->flatten()->unique()->all();

                                // 3. Ambil gudang di plant sumber yang cocok tipenya
                                return Warehouse::where('plant_id', $record->source_plant_id)
                                    ->whereIn('type', $warehouseTypes) // Filter berdasarkan tipe yang relevan
                                    ->where('status', true)
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->required()
                            ->live() // <-- PENTING agar dropdown user bereaksi
                            ->helperText('Pilih gudang spesifik di plant sumber untuk picking.'),

                        // =MATICS
                        Select::make('assigned_user_id')
                            ->label('Assign Picking Task To')
                            ->options(function (Get $get): array {
                                $sourceWarehouseId = $get('source_warehouse_id');
                                if (!$sourceWarehouseId) {
                                    return []; // Kosongkan jika warehouse belum dipilih
                                }

                                // Filter user yang 'locationable'-nya adalah Warehouse ini
                                // DAN punya role Staff Gudang/Manager
                                return User::where('locationable_type', Warehouse::class)
                                            ->where('locationable_id', $sourceWarehouseId)
                                            ->where('status', true) // Hanya user aktif
                                            ->whereHas('position', fn ($q) => $q->whereIn('name', ['Staff Gudang', 'Manager Gudang']))
                                            ->pluck('name', 'id')
                                            ->toArray();
                            })
                            // ==========================================================
                            // --- PERBAIKAN: Hapus 'searchable' & 'getSearchResultsUsing' ---
                            // ==========================================================
                            ->preload() // <-- Preload opsinya setelah warehouse dipilih
                            ->required()
                            ->searchable()
                            // 'searchable()' dan 'getSearchResultsUsing()' dihapus untuk
                            // menghindari bug 'Relation, null given'.
                            // ==========================================================
                            ->helperText('Hanya menampilkan staf yang ditugaskan di Warehouse yang dipilih.'),
                    ];
                })
                // ==========================================================
                ->action(function (array $data) use ($record) {
                    try {
                        DB::transaction(function () use ($record, $data) {
                             if ($record->pickingLists()->where('status', '!=', 'cancelled')->exists()) {
                                throw ValidationException::withMessages(['error' => 'An active picking list already exists.']);
                            }

                            $sourceWarehouseId = $data['source_warehouse_id']; // <-- Ambil dari form
                            $record->loadMissing('items.product'); // Load product

                            // 1. Inisialisasi Service
                        $strategyService = new PutawayStrategyService();

                        // 2. Ambil ID Lokasi yang Valid (TETAP SAMA)
                        $sellableLocationIds = Location::where('locatable_type', Warehouse::class)
                                ->where('locatable_id', $sourceWarehouseId)
                                ->where('is_sellable', true)
                                ->where('status', true)
                                ->where('ownership_type', 'owned')
                                ->pluck('id')->toArray();

                        if (empty($sellableLocationIds)) {
                            throw ValidationException::withMessages(['error' => "No active, sellable, 'owned' locations found."]);
                        }

                        // Buat Header Picking List (TETAP SAMA)
                        $pickingList = $record->pickingLists()->create([
                            'picking_list_number' => 'PL-ST-' . date('Ym') . '-' . random_int(1000, 9999),
                            'user_id' => $data['assigned_user_id'],
                            'status' => 'pending',
                            'warehouse_id' => $sourceWarehouseId,
                            'business_id' => $record->business_id,
                        ]);

                        $picker = User::find($data['assigned_user_id']);

                        if ($picker) {
                            // Menggunakan Notification Class yang sama dengan Putaway
                            $picker->notify(new \App\Notifications\TaskAssignedNotification(
                                'Picking',                          // Tipe Tugas (Case Sensitive untuk Title, tapi di lowercase di logic payload)
                                $pickingList->picking_list_number,  // Nomor Dokumen
                                $pickingList->id                    // ID Referensi (untuk navigasi)
                            ));
                        }

                        // Loop Item
                        foreach ($record->items as $item) {
                            $item->loadMissing('product.uoms'); // Load uoms untuk konversi
                            $uom = $item->product?->uoms->where('uom_name', $item->uom)->first();
                            $totalQtyToPick = $item->quantity * ($uom?->conversion_rate ?? 1);

                            if ($totalQtyToPick <= 0) continue;

                            $pickingListItem = $pickingList->items()->create([
                                'product_id' => $item->product_id,
                                'total_quantity_to_pick' => $totalQtyToPick,
                                'uom' => $item->product->base_uom
                            ]);

                            // ============================================================
                            // [UPDATE UTAMA] LOGIKA DINAMIS MENGGUNAKAN SERVICE
                            // ============================================================

                            // 1. Dapatkan Urutan ID Zona Prioritas dari Database Rules
                            $targetZoneIds = $strategyService->getPickingZonePriorities($item->product);

                            // ============================================================

                            // Query Dasar Inventory (TETAP SAMA)
                            $inventoryQueryBase = Inventory::whereIn('location_id', $sellableLocationIds)
                                ->where('product_id', $item->product_id)
                                ->where('avail_stock', '>', 0.0001);

                            $allocatedQty = 0;
                            $remainingToPick = $totalQtyToPick;

                            // --- STRATEGI 1: Cari berdasarkan Prioritas Zona (Dinamis) ---
                            foreach ($targetZoneIds as $zoneId) {
                                if ($remainingToPick <= 0.0001) break;

                                $inventoriesInZone = (clone $inventoryQueryBase)
                                                ->whereHas('location', fn($q) => $q->where('zone_id', $zoneId))
                                                ->orderBy('sled', 'asc') // FEFO
                                                ->orderBy('id', 'asc')   // FIFO
                                                ->get();

                                foreach ($inventoriesInZone as $inventory) {
                                    if ($remainingToPick <= 0.0001) break;

                                    $qtyFromThisBatch = min($remainingToPick, (float)$inventory->avail_stock);

                                    $pickingListItem->sources()->create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_to_pick_from_source' => $qtyFromThisBatch
                                    ]);

                                    $remainingToPick -= $qtyFromThisBatch;
                                    $allocatedQty += $qtyFromThisBatch;
                                }
                            }

                            // --- STRATEGI 2: SAFETY NET (Cari di mana saja selain zona di atas) ---
                            // Ini tetap perlu ada, siapa tahu barang nyasar di zona 'Quality' atau 'Return' yang layak jual
                            if ($remainingToPick > 0.0001) {
                                $otherInventories = (clone $inventoryQueryBase)
                                    ->whereHas('location', fn($q) => $q->whereNotIn('zone_id', $targetZoneIds))
                                    ->orderBy('sled', 'asc')
                                    ->get();

                                foreach ($otherInventories as $inventory) {
                                    if ($remainingToPick <= 0.0001) break;

                                    $qtyFromThisBatch = min($remainingToPick, (float)$inventory->avail_stock);

                                    $pickingListItem->sources()->create([
                                        'inventory_id' => $inventory->id,
                                        'quantity_to_pick_from_source' => $qtyFromThisBatch
                                    ]);

                                    $remainingToPick -= $qtyFromThisBatch;
                                    $allocatedQty += $qtyFromThisBatch;
                                }
                            }

                            // Cek Akhir
                            if ($allocatedQty < ($totalQtyToPick - 0.001)) {
                                 // Ambil nama-nama zona untuk pesan error yang informatif
                                 $zoneNames = Zone::whereIn('id', $targetZoneIds)->pluck('code')->implode(', ');
                                 throw ValidationException::withMessages(['error' => "Insufficient 'owned' stock for '{$item->product->name}'. Need: $totalQtyToPick, Found: $allocatedQty. (Searched in Priority Zones: $zoneNames + Others)"]);
                            }
                        }

                        $record->update(['status' => 'processing']);
                        });
                        Notification::make()->title('Picking list generated successfully!')->success()->send();
                        // Refresh form untuk update status
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Failed to generate picking list')->body($e->getMessage())->danger()->send();
                    } catch (\Exception $e) {
                         Log::error("GeneratePickingList (STO) Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                         Notification::make()->title('Error')->body('An unexpected error occurred: '.$e->getMessage())->danger()->send();
                    }
                }),


            // AKSI 4: CANCEL
            Actions\Action::make('cancel')
                ->label('Cancel Transfer')
                ->action(fn ($record) => $record->update(['status' => 'cancelled']))
                ->requiresConfirmation()->color('danger')->icon('heroicon-o-x-circle')
                ->visible(fn ($record) =>
                    in_array($record->status, ['draft', 'pending_approval', 'approved']) &&
                    $this->check(Auth::user(), 'cancel stock transfers'))
        ];
    }
}
