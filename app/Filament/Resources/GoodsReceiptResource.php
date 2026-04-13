<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GoodsReceiptResource\Pages;
use App\Filament\Resources\GoodsReceiptResource\RelationManagers;
use App\Models\GoodsReceipt;
use App\Models\Plant;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;
    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';
    protected static ?string $navigationGroup = 'Inventory Management';
    protected static ?string $navigationLabel = 'Goods Receipts';

    // Pengguna tidak bisa membuat atau mengedit GR secara manual
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; } // Gunakan Model type hint

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        // Pastikan user login dan punya business_id
        if (!$user || !$user->business_id) {
            return parent::getEloquentQuery()->whereRaw('0 = 1');
        }

        // Mulai query dasar (sudah difilter oleh 'parent')
        $query = parent::getEloquentQuery();

        // Owner bisa melihat semua GR di bisnisnya (langsung filter GR)
        if ($user->hasRole('Owner')) { // Asumsi pakai Spatie HasRoles
            $query->where('business_id', $user->business_id);
        }
        // Staff Gudang hanya melihat GR yang ditujukan ke gudangnya
        elseif ($user->locationable_type === Warehouse::class && $user->locationable_id) {
            $query->where('business_id', $user->business_id) // Selalu filter by business
                 ->where('warehouse_id', $user->locationable_id);
        }
        // User lain (misal Manager)
        else {
             $query->where('business_id', $user->business_id);
        }

        // ==========================================================
        // --- TAMBAHAN: Eager Load untuk Kolom Pintar ---
        // =GET_QUERY_UPDATE
        // ==========================================================
        // Muat relasi yang akan digunakan di tabel untuk menghindari N+1
        $query->with([
            'purchaseOrder:id,po_number,plant_id', // Ambil PO
            'purchaseOrder.plant:id,name',         // Ambil Plant dari PO
            'shipment:id,shipment_number,destination_plant_id', // <-- 'deleted_at' DIHAPUS
            'shipment.destinationPlant:id,name',   // Ambil Plant dari Shipment
            'warehouse:id,name',                   // Gudang penerima
            'receivedBy:id,name'                   // User penerima
        ]);
        // ==========================================================

        return $query;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Details')
                    ->schema([
                        TextEntry::make('receipt_number'),

                        // ==========================================================
                        // --- PERBAIKAN: Kolom Pintar (Source Document) ---
                        // ==========================================================
                        TextEntry::make('source_document')
                            ->label('Source Document')
                            ->getStateUsing(function (GoodsReceipt $record) {
                                // Data sudah di-eager load oleh getRecord() atau getEloquentQuery()
                                if ($record->purchaseOrder) {
                                    return $record->purchaseOrder->po_number . " (PO)";
                                }
                                if ($record->shipment) {
                                    return $record->shipment->shipment_number . " (DO)";
                                }
                                return 'N/A';
                            }),

                        // ==========================================================
                        // --- PERBAIKAN: Kolom Pintar (Destination Plant) ---
                        // ==========================================================
                        TextEntry::make('destination_plant')
                            ->label('Destination Plant')
                            ->getStateUsing(function (GoodsReceipt $record) {
                                if ($record->purchaseOrder) {
                                    return $record->purchaseOrder->plant?->name;
                                }
                                if ($record->shipment) {
                                    return $record->shipment->destinationPlant?->name;
                                }
                                return 'N/A';
                            }),
                        // ==========================================================

                        TextEntry::make('warehouse.name')->label('Receiving Warehouse'),
                        TextEntry::make('receivedBy.name')->label('Received By'),
                        TextEntry::make('receipt_date')->date(),
                        TextEntry::make('status')->label('Status GR'),
                        TextEntry::make('notes')->columnSpanFull(),
                    ])->columns(2),

                Section::make('Items Received')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.name')->columnSpan(2),
                                TextEntry::make('quantity_received'),
                                TextEntry::make('uom'),
                                TextEntry::make('batch'),
                                TextEntry::make('sled')->date(),
                                TextEntry::make('location.name')->label('Received Location'),
                            ])->columns(6),
                    ]),
            ]);
    }
    // ==========================================================

    // ==========================================================
    // --- PERBAIKAN table (Kolom Pintar) ---
    // ==========================================================
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('receipt_number')->searchable(),

                // ==========================================================
                // --- PERBAIKAN: Kolom Pintar (Source Document) ---
                // ==========================================================
                Tables\Columns\TextColumn::make('source_document')
                    ->label('Source Document')
                    ->getStateUsing(function (GoodsReceipt $record) {
                        // Data sudah di-eager load oleh getEloquentQuery
                        if ($record->purchaseOrder) {
                            return $record->purchaseOrder->po_number . " (PO)";
                        }
                        if ($record->shipment) {
                            return $record->shipment->shipment_number . " (DO)";
                        }
                        return 'N/A';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Cari di kedua relasi
                        return $query->whereHas('purchaseOrder', fn($q) => $q->where('po_number', 'like', "%{$search}%"))
                                     ->orWhereHas('shipment', fn($q) => $q->where('shipment_number', 'like', "%{$search}%"));
                    }),
                // ==========================================================

                // ==========================================================
                // --- PERBAIKAN: Kolom Pintar (Plant) ---
                // ==========================================================
                Tables\Columns\TextColumn::make('destination_plant')
                    ->label('Plant')
                    ->getStateUsing(function (GoodsReceipt $record) {
                        // Data sudah di-eager load
                        if ($record->purchaseOrder) {
                            return $record->purchaseOrder->plant?->name;
                        }
                        if ($record->shipment) {
                            return $record->shipment->destinationPlant?->name;
                        }
                        return 'N/A';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Cari di kedua relasi
                        return $query->whereHas('purchaseOrder.plant', fn($q) => $q->where('name', 'like', "%{$search}%"))
                                     ->orWhereHas('shipment.destinationPlant', fn($q) => $q->where('name', 'like', "%{$search}%"));
                    })
                    ->sortable(),
                // ==========================================================

                Tables\Columns\TextColumn::make('warehouse.name')->label('Warehouse')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('receivedBy.name')->label('Received By'),
                Tables\Columns\TextColumn::make('receipt_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
            ])
             ->filters([
                 // ==========================================================
                 // --- PERBAIKAN: Filter Pintar (Plant) ---
                 // ==========================================================
                 Tables\Filters\SelectFilter::make('plant_id')
                     ->label('Plant')
                     ->options(fn () => Plant::where('business_id', Auth::user()->business_id)->pluck('name', 'id'))
                     ->query(function (Builder $query, array $data): Builder {
                         $plantId = $data['value'];
                         if (empty($plantId)) return $query;

                         // Cari di PO atau di Shipment
                         return $query->where(function (Builder $q) use ($plantId) {
                             $q->whereHas('purchaseOrder', fn($subq) => $subq->where('plant_id', $plantId))
                               ->orWhereHas('shipment', fn($subq) => $subq->where('destination_plant_id', $plantId));
                         });
                     })
                     ->searchable()
                     ->preload(),
                 // ==========================================================

                 Tables\Filters\SelectFilter::make('warehouse') // Filter berdasarkan Warehouse GR
                     ->relationship('warehouse', 'name')
                     ->searchable()
                     ->preload(),
             ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }


    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGoodsReceipts::route('/'),
            'view' => Pages\ViewGoodsReceipt::route('/{record}'),
             // Rute 'receive' tetap sama, parameter {po} sudah benar
            'receive' => Pages\ReceiveGoods::route('/receive/{po:id}'),
            'receive-shipment' => Pages\ReceiveShipment::route('/receive-shipment/{shipment:id}'),
        ];
    }
}
