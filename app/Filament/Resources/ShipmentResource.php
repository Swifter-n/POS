<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShipmentResource\Pages;
use App\Filament\Resources\ShipmentResource\RelationManagers;
use App\Models\Fleet;
use App\Models\GoodsReturn;
use App\Models\Outlet;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
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
    // KONTROL HAK AKSES & DATA (DIPERBARUI)
    // ==========================================================

    public static function canViewAny(): bool
    {
        return self::userHasPermission('view shipments');
    }

    public static function canCreate(): bool { return false; } // Tetap false, dibuat dari STO/SO
    public static function canEdit(Model $record): bool { return true; } // Izinkan untuk 'View'/Edit

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        if (!$user || !$user->business_id) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        $query = parent::getEloquentQuery()->where('business_id', $user->business_id);

        // Owner bisa melihat semua
        if (self::userHasRole('Owner')) {
            return $query;
        }

        // --- Filter untuk Non-Owner ---
        $userPlantId = null;
        $userOutletId = null;

        // Cek apakah user terhubung ke Warehouse (dan dapatkan Plant-nya)
        $user->loadMissing('locationable'); // Pastikan relasi di-load
        if ($user->locationable_type === Warehouse::class && $user->locationable?->plant_id) {
            $userPlantId = $user->locationable->plant_id;
        }
        // Cek apakah user terhubung ke Outlet
        elseif ($user->locationable_type === Outlet::class && $user->locationable_id) {
             $userOutletId = $user->locationable_id;
        }

        $query->where(function (Builder $q) use ($user, $userPlantId, $userOutletId) {
            // 1. User adalah PENGIRIM (Picker)
            $q->where('picker_user_id', $user->id);

            // 2. Plant user adalah SUMBER
            if ($userPlantId) $q->orWhere('source_plant_id', $userPlantId);

            // 3. Plant user adalah TUJUAN
            if ($userPlantId) $q->orWhere('destination_plant_id', $userPlantId);

            // 4. Outlet user adalah TUJUAN
            if ($userOutletId) $q->orWhere('destination_outlet_id', $userOutletId);

            // ==========================================================
            // --- INI SUDAH BENAR ---
            // (Menggunakan relasi 'salesOrders' yang valid, bukan 'sourceables')
            // ==========================================================
            // 5. User adalah Salesman (hanya untuk SalesOrder)
            $q->orWhereHas('salesOrders', function (Builder $q_so) use ($user) {
                $q_so->where('salesman_id', $user->id);
            });
            // ==========================================================
        });

        // Eager load relasi dasar untuk tabel
        // Ini adalah kunci agar tabel cepat dan bebas N+1
        $query->with([
            'sourceWarehouse:id,name',
            'destinationPlant:id,name',
            'destinationOutlet:id,name',
            'customer:id,name' // Load customer (dari kolom customer_id)
        ]);

        return $query;
    }

    // ==========================================================
    // FORM (InfoList) DIPERBARUI UNTUK PLANT
    // ==========================================================
    public static function form(Form $form): Form
{
    return $form->schema([
        Group::make()->schema([
            Section::make('Shipment Details')->schema([
                TextInput::make('shipment_number')->readOnly(),

                // --- 1. PERBAIKAN LOGIKA SOURCE (Mendukung Vendor dari PO) ---
                Placeholder::make('source_display')
                    ->label('Source / Origin')
                    ->content(function (Model $record) {
                        // Load semua kemungkinan relasi sumber
                        $record->loadMissing(['sourcePlant', 'sourceWarehouse', 'purchaseOrders.vendor']);

                        // Skenario A: Internal Shipment (STO/SO) - Dari Plant/Gudang Sendiri
                        $plantName = $record->sourcePlant?->name;
                        $warehouseName = $record->sourceWarehouse?->name;
                        if ($plantName || $warehouseName) {
                            return ($plantName ?? '') . ($plantName && $warehouseName ? ' > ' : '') . ($warehouseName ?? '');
                        }

                        // Skenario B: Inbound Shipment (PO) - Dari Vendor
                        $po = $record->purchaseOrders->first();
                        if ($po && $po->vendor) {
                            return $po->vendor->name . ' (Vendor)';
                        }

                        return 'N/A';
                    }),

                // --- Logika Destination (Sudah Aman) ---
                Placeholder::make('destination_display')
                    ->label('Destination')
                    ->content(function (Model $record) {
                        $record->loadMissing(['destinationPlant', 'destinationOutlet', 'customer']);
                        if ($record->destinationPlant) return $record->destinationPlant->name . ' (Plant/DC)';
                        if ($record->destinationOutlet) return $record->destinationOutlet->name . ' (Outlet)';
                        if ($record->customer) return $record->customer->name . ' (Customer)';
                        return 'N/A';
                    }),
            ])->columns(2),

            // --- 2. PERBAIKAN LOGIKA SOURCE DOCUMENTS (Mendukung PO) ---
            Section::make('Source Documents')
    ->schema([
        Placeholder::make('source_documents_list')
            ->label('')
            ->content(function (Model $record) {
                // PERBAIKAN: Load relasi asli
                $record->loadMissing(['purchaseOrders', 'salesOrders', 'stockTransfers']);

                // Panggil accessor (aman karena data relasi sudah ada di memori)
                $sourceables = $record->sourceables;

                if ($sourceables->isEmpty()) {
                    return 'No source documents linked.';
                }

                $html = '<ul class="list-disc list-inside">';

                foreach ($sourceables as $src) {
                    $type = 'UNK';
                    $number = 'N/A';
                    $extraInfo = '';

                    if ($src instanceof \App\Models\PurchaseOrder) {
                        $type = 'PO';
                        $number = $src->po_number;
                        $extraInfo = " - " . ($src->vendor?->name ?? 'Unknown Vendor');
                    }
                    elseif ($src instanceof \App\Models\SalesOrder) {
                        $type = 'SO';
                        $number = $src->so_number;
                        $extraInfo = " - " . ($src->customer?->name ?? 'Unknown Customer');
                    }
                    elseif ($src instanceof \App\Models\StockTransfer) {
                        $type = 'STO';
                        $number = $src->transfer_number;
                    }

                    $html .= "<li><strong>[{$type}]</strong> {$number}{$extraInfo}</li>";
                }

                $html .= '</ul>';
                return new \Illuminate\Support\HtmlString($html);
            }),
    ]),

            Section::make('Scheduling & Costs')->schema([
                DatePicker::make('scheduled_for')->readOnly(),
                DatePicker::make('estimated_time_of_arrival')->readOnly(),
                // Field ini sudah benar (akan menampilkan data dari DB)
                TextInput::make('transport_cost')->numeric()->prefix('Rp')->readOnly(),
                Select::make('picker_user_id')->relationship('picker', 'name')->disabled(),
            ])->columns(2),
        ])->columnSpan(['lg' => 2]),

        Group::make()->schema([
            Section::make('Status')->schema([
                TextInput::make('status')->readOnly(),
                DateTimePicker::make('shipped_at')->readOnly(),
                DateTimePicker::make('delivered_at')->readOnly(),
            ]),
        ])->columnSpan(['lg' => 1]),
    ])->columns(3);
}

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shipment_number')->searchable(),

                // --- Kolom Source Number BARU (Menampilkan list) ---
                Tables\Columns\TextColumn::make('sourceables_list')
    ->label('Source Document')
    ->listWithLineBreaks()
    ->limitList(2)
    ->getStateUsing(function (Model $record) {
        // PERBAIKAN: Jangan load 'sourceables', tapi load relasi aslinya
        $record->loadMissing(['purchaseOrders', 'salesOrders', 'stockTransfers']);

        // Sekarang panggil accessor (yang akan menggabungkan data yang sudah di-load di atas)
        return $record->sourceables->map(function ($src) {
            // Cek tipe dokumen dan kembalikan nomor yang sesuai
            if ($src instanceof \App\Models\PurchaseOrder) {
                return $src->po_number . ' (PO)';
            }
            if ($src instanceof \App\Models\SalesOrder) {
                return $src->so_number . ' (SO)';
            }
            if ($src instanceof \App\Models\StockTransfer) {
                return $src->transfer_number . ' (STO)';
            }
            return 'N/A';
        })->filter()->implode("\n");
    }),


                Tables\Columns\TextColumn::make('source_display') // Ubah nama kolom agar tidak conflict dengan relasi
                ->label('From (Source)')
                ->getStateUsing(function (Model $record) {
                    // Skenario 1: Internal (STO/SO) - Punya Source Warehouse
                    if ($record->sourceWarehouse) {
                        return $record->sourceWarehouse->name . ' (Whs)';
                    }

                    // Skenario 2: Inbound (PO) - Punya Vendor
                    // Kita cek lewat relasi purchaseOrders
                    $po = $record->purchaseOrders->first();
                    if ($po && $po->vendor) {
                        return $po->vendor->name . ' (Vendor)';
                    }

                    return 'External / N/A';
                })
                ->description(function (Model $record) {
                    // Opsional: Tampilkan Plant Asal jika ada
                    if ($record->sourcePlant) {
                        return $record->sourcePlant->name;
                    }
                    return null;
                }),

                // Tables\Columns\TextColumn::make('sourceWarehouse.name')
                //     ->label('From (Warehouse)')
                //     ->searchable()
                //     ->sortable(),

                // --- Kolom Destination BARU (Hanya dari Shipment) ---
                Tables\Columns\TextColumn::make('display_destination')
                    ->label('To (Destination)')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                         // Cari di relasi langsung Shipment
                        return $query
                            ->orWhereHas('destinationPlant', fn($q) => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('destinationOutlet', fn($q) => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn($q) => $q->where('name', 'like', "%{$search}%"));
                    })
                    ->getStateUsing(function (Model $record) {
                    if ($record->destinationPlant) return $record->destinationPlant->name . ' (Plant)';
                    if ($record->destinationOutlet) return $record->destinationOutlet->name . ' (Outlet)';
                    if ($record->customer) return $record->customer->name . ' (Cust)';
                    return 'N/A';
                }),
                // ==========================================================

                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'ready_to_ship',
                    'warning' => 'shipping',
                    'success' => 'received',
                    'danger' => 'cancelled',
                ]),
                Tables\Columns\TextColumn::make('shipped_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('delivered_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source_plant_id')
                    ->relationship('sourcePlant', 'name')
                    ->searchable()->preload(),
                Tables\Filters\SelectFilter::make('destination_plant_id')
                    ->relationship('destinationPlant', 'name')
                    ->searchable()->preload(),
                 Tables\Filters\SelectFilter::make('customer_id')
                    ->relationship('customer', 'name') // Relasi baru
                    ->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('View Details'), // Ganti label jadi View
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
            RelationManagers\FleetsRelationManager::class,
            \App\Filament\Resources\ShipmentResource\RelationManagers\ItemsRelationManager::class,

        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
            //'create' => Pages\CreateShipment::route('/create'),
            'edit' => Pages\EditShipment::route('/{record}/edit'),
        ];
    }
}
