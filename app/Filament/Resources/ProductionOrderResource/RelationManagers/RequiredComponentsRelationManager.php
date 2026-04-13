<?php

namespace App\Filament\Resources\ProductionOrderResource\RelationManagers;

use App\Models\BomItem;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RequiredComponentsRelationManager extends RelationManager
{
    // Ini adalah 'trik', relasinya tidak langsung
    // Kita akan override query-nya
    protected static string $relationship = 'items';
    protected static ?string $recordTitleAttribute = 'product.name';
    protected static ?string $title = 'Required Components (BOM)';

    public ?string $displayUom = 'base';

    // Kita tidak bisa 'create' atau 'edit' BOM dari sini
    public function canCreate(): bool { return false; }
    public function canEdit(Model $record): bool { return false; }
    public function canDelete(Model $record): bool { return false; }
    public function canDeleteAny(): bool { return false; }

    // Override form() agar kosong
    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * Ini adalah logika kustom untuk mengambil BOM Items
     * berdasarkan Production Order (parent).
     */
    public function getTableQuery(): Builder
    {
        $productionOrder = $this->getOwnerRecord();

        if (!$productionOrder || !$productionOrder->finished_good_id) { // Tambahkan cek $productionOrder
             // Jika produk jadi belum dipilih, kembalikan query kosong
             return BomItem::query()->whereRaw('0 = 1');
        }

        // Query: ProductionOrder -> Product (FG) -> Bom -> BomItems
        return BomItem::query()
                ->whereHas('bom', function (Builder $query) use ($productionOrder) {
                    $query->where('product_id', $productionOrder->finished_good_id);
                })
                ->with('product'); // Eager load produk komponen
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn() => $this->getTableQuery()) // Gunakan query kustom
            // ==========================================================
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Component'),
                Tables\Columns\TextColumn::make('product.sku')
                    ->label('SKU'),

                // Tampilkan Qty Asli dari BOM
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty per Unit (BOM)'),
                Tables\Columns\TextColumn::make('uom'),
                Tables\Columns\TextColumn::make('usage_type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucwords(strtolower(str_replace('_', ' ', $state))) : '-') // Handle null
                    ->color(fn (string $state): string => match ($state) {
                        'RAW_MATERIAL' => 'success',
                        'RAW_MATERIAL_STORE' => 'info',
                        'BY_PRODUCT' => 'warning',
                        default => 'gray',
                    }),
                // ==========================================================

                // Kolom Placeholder untuk Qty Total (Dihitung)
                Tables\Columns\TextColumn::make('total_required')
                    ->label('Total Qty Required')
                    // Panggil helper baru untuk menghitung qty
                    ->getStateUsing(function (Model $record, RelationManager $livewire) {
                        return $this->getConvertedTotal($record, $livewire->displayUom);
                    })
                    ->numeric(),
            ])
            ->paginated(false) // Matikan paginasi (biasanya BOM item sedikit)
            ->headerActions([
                 Tables\Actions\Action::make('changeDisplayUom')
                    ->label('Display Qty In')
                    ->icon('heroicon-o-cube')
                    ->color('gray')
                    ->form(function ($livewire) {
                        return [
                            Select::make('displayUomSelect')
                                ->label('Select Unit of Measure')
                                ->live()
                                ->options(function () use ($livewire): array {

                                    // PERBAIKAN LOGIKA:
                                    // Owner record adalah ProductionOrder
                                    $productionOrder = $livewire->getOwnerRecord()
                                        ->load('finishedGood.bom.items.product.uoms'); // Eager load

                                    // 1. Ambil Base UoM dari produk jadi
                                    $baseUomName = $productionOrder->finishedGood?->base_uom ?? 'PCS';

                                    // 2. Kumpulkan UoM unik dari *semua komponen BOM*
                                    $availableUoms = $productionOrder->finishedGood?->bom?->items
                                        ->pluck('product.uoms')
                                        ->flatten()
                                        ->whereNotNull()
                                        ->pluck('uom_name')
                                        ->unique()
                                        ->sort()
                                        ->values()
                                        ->all();

                                    // 3. Buat array opsi
                                    $options = ['base' => "Base UoM (Default)"];
                                    foreach ($availableUoms as $uom) {
                                        // Jangan duplikat jika Base UoM ada di daftar
                                        if (strcasecmp($uom, $baseUomName) !== 0) {
                                            $options[$uom] = $uom;
                                        }
                                    }
                                    return $options;
                                })
                                ->default($livewire->displayUom)
                                // Gunakan $livewire->property saat di dalam action
                                ->afterStateUpdated(fn ($state) => $livewire->displayUom = $state),
                        ];
                    })
            ]);
    }

    private function getConvertedTotal(Model $record, ?string $displayUom): string
    {
        // $record adalah BomItem
        $record->loadMissing('product.uoms');
        $product = $record->product;
        if (!$product) return 'N/A';

        // 1. Dapatkan Qty Total dalam Base UoM
        $plannedQty = (float) $this->getOwnerRecord()->quantity_planned;
        $bomQty = (float) $record->quantity; // Qty dari BOM
        $bomUomName = $record->uom; // UoM dari BOM (misal: 'Gram')

        // 2. Konversi Qty BOM ke Base UoM komponen itu sendiri
        $bomUom = $product->uoms->where('uom_name', $bomUomName)->first();
        $conversionRate = $bomUom?->conversion_rate ?? 1;

        $totalBaseQty = ($plannedQty * $bomQty) * $conversionRate;
        $baseUomName = $product->base_uom ?? 'UoM';

        // 3. Cek apakah perlu konversi untuk display
        if ($displayUom === 'base' || $displayUom === null || strcasecmp($displayUom, $baseUomName) === 0) {
            return number_format($totalBaseQty, 4, '.', '') . ' ' . $baseUomName;
        }

        // 4. Lakukan konversi ke UoM Display yang dipilih
        $targetUom = $product->uoms->where('uom_name', $displayUom)->first();

        // Jika UoM tidak ada di produk ini, atau conversion rate-nya 0, kembalikan base
        if (!$targetUom || $targetUom->conversion_rate == 0) {
             return number_format($totalBaseQty, 4, '.', '') . ' ' . $baseUomName;
        }

        // Konversi dari Base Qty ke Display Qty
        $displayQty = $totalBaseQty / $targetUom->conversion_rate;

        $formattedQty = number_format($displayQty, 4, '.', '');

        return $formattedQty . ' ' . $displayUom;
    }


}
