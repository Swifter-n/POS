<?php

namespace App\Filament\Resources\PickingListResource\RelationManagers;

use App\Models\PickingList;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

       public ?string $displayUom = null;

    // Inisialisasi $displayUom saat komponen dimuat
    public function mount(): void
    {
        // Default ke Base UoM saat pertama kali dibuka
        // Kita gunakan string 'base' sebagai penanda
        $this->displayUom = 'base';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Placeholder::make('product_name')
                     ->label('Product')
                     // Ambil nama produk dari relasi record item saat ini
                     ->content(fn (Model $record) => $record->product?->name ?? 'N/A'),

                // ==========================================================
                // --- Placeholder Qty Allocated dengan Logika Mixed UoM ---
                // ==========================================================
                Placeholder::make('total_quantity_to_pick_display') // Beri nama unik
                    ->label('Qty to Pick (Allocated)')
                    ->content(function (Model $record): string {
                         // Ambil nilai asli (base uom) dari record
                        $qtyInBase = (int) $record->total_quantity_to_pick; // Pastikan integer
                        $baseUomName = $record->product?->base_uom ?? 'PCS';

                        // Jika user memilih 'base' atau UoM tidak ditemukan/sama, tampilkan apa adanya
                        if ($this->displayUom === 'base' || $this->displayUom === $baseUomName || !$record->product) {
                            return "{$qtyInBase} {$baseUomName}";
                        }

                        // Pastikan relasi UoM dimuat
                        $record->loadMissing('product.uoms');

                        // Cari UoM target di relasi produk
                        $targetUom = $record->product?->uoms
                                        ->where('uom_name', $this->displayUom)
                                        ->first();

                        // Jika UoM target ada dan punya conversion rate > 1
                        if ($targetUom && ($conversionRate = (int)$targetUom->conversion_rate) > 1) {
                            $wholeUnits = floor($qtyInBase / $conversionRate);
                            $remainder = $qtyInBase % $conversionRate;

                            if ($remainder == 0) {
                                return "{$wholeUnits} {$this->displayUom}"; // Hasil pas
                            } elseif ($wholeUnits == 0) {
                                return "{$remainder} {$baseUomName}"; // Kurang dari 1 UoM target
                            } else {
                                // Format campuran
                                return "{$wholeUnits} {$this->displayUom} + {$remainder} {$baseUomName}";
                            }
                        }

                        // Fallback jika konversi gagal atau rate <= 1
                        return "{$qtyInBase} {$baseUomName}";
                    }),
                // ==========================================================

                Select::make('input_uom')
                    ->label('Counted In (UoM)')
                    ->options(function (Model $record): array {
                        if (!$record->product) return [];
                        $record->loadMissing('product.uoms');
                        return $record->product->uoms->pluck('uom_name', 'uom_name')->toArray();
                    })
                    ->required()
                    ->default(fn (Model $record) => $record->product?->base_uom ?? 'PCS')
                    ->helperText('Pilih satuan yang Anda gunakan untuk menghitung.'),

                TextInput::make('input_quantity')
                    ->label('Quantity Counted')
                    ->numeric()
                    ->nullable()
                    ->autofocus()
                    ->helperText('Masukkan jumlah yang Anda hitung dalam satuan di atas.'),

                // Field quantity_picked asli tidak lagi ditampilkan di form
                // tapi akan dihitung saat disimpan.
            ]);
    }

    public function table(Table $table): Table
    {
        $pickingList = $this->getOwnerRecord();
        $user = Auth::user();
        $userId = $user?->id;

        return $table
            ->recordTitleAttribute('product.name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'product.uoms', // <-- Tambahkan ini
                'sources.inventory.location'
            ]))
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product'),

                TextColumn::make('product.sku')
                    ->label('SKU'),

                TextColumn::make('total_quantity_to_pick')
                    ->label('Qty to Pick')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(function ($state, Model $record): string {
                         // Ambil nilai asli (base uom) dari record
                        $qtyInBase = (int) $state; // Pastikan integer
                        $baseUomName = $record->product?->base_uom ?? 'PCS';

                        // Jika user memilih 'base' atau UoM tidak ditemukan/sama, tampilkan apa adanya
                        if ($this->displayUom === 'base' || $this->displayUom === $baseUomName || !$record->product) {
                            return "{$qtyInBase} {$baseUomName}";
                        }

                        // Cari UoM target di relasi produk (sudah di-load via modifyQueryUsing)
                        $targetUom = $record->product?->uoms
                                        ->where('uom_name', $this->displayUom)
                                        ->first();

                        // Jika UoM target ada dan punya conversion rate > 1
                        if ($targetUom && ($conversionRate = (int)$targetUom->conversion_rate) > 1) {
                            $wholeUnits = floor($qtyInBase / $conversionRate);
                            $remainder = $qtyInBase % $conversionRate;

                            if ($remainder == 0) {
                                return "{$wholeUnits} {$this->displayUom}"; // Hasil pas
                            } elseif ($wholeUnits == 0) {
                                return "{$remainder} {$baseUomName}"; // Kurang dari 1 UoM target
                            } else {
                                // Format campuran
                                return "{$wholeUnits} {$this->displayUom} + {$remainder} {$baseUomName}";
                            }
                        }

                        // Fallback jika konversi gagal atau rate <= 1
                        return "{$qtyInBase} {$baseUomName}";
                    }),

                // Kolom "Instruksi Picking" (Pick From)
                Tables\Columns\TextColumn::make('sources')
                    ->label('Pick From (Location > Batch > Qty)')
                    ->listWithLineBreaks()
                    ->formatStateUsing(function (Model $record): string {
                        if (! $record->relationLoaded('sources')) {
                            return 'Loading...';
                        }
                        $baseUom = $record->product?->base_uom ?? 'PCS';

                        return $record->sources->map(fn($source) =>
                            "• " .
                            ($source->inventory?->location?->name ?? 'N/A') . " > " .
                            ($source->inventory?->batch ?? 'N/A') . " " .
                            "({$source->quantity_to_pick_from_source} {$baseUom})"
                        )->implode("\n");
                    }),

                // Kolom "Konfirmasi Picking" (Qty Picked)
                // Tampilan kolom ini tetap dalam Base UoM agar konsisten dengan input
                TextColumn::make('quantity_picked')
                    ->label('Qty Picked (Base UoM)') // Perjelas label
                    ->badge()
                    ->color(function (Model $record): string {
                        $picked = $record->quantity_picked;
                        $allocated = $record->total_quantity_to_pick;
                        if ($picked === null) {
                            return 'danger';
                        }
                        if ($picked < $allocated) {
                            return 'warning';
                        }
                        return 'success';
                    })
                    ->formatStateUsing(function ($state, Model $record): string {
                         $baseUomName = $record->product?->base_uom ?? 'PCS';
                         return $state === null ? 'Not Picked' : "{$state} {$baseUomName}";
                    }),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //Tables\Actions\CreateAction::make(),
                 Tables\Actions\Action::make('changeDisplayUom')
                    ->label('Display Qty In')
                    ->icon('heroicon-o-cube')
                    ->color('gray')
                    // Menggunakan Form Action untuk menampilkan Select
                    ->form(function ($livewire) { // <-- Terima $livewire di closure utama
                        return [
                            Select::make('displayUomSelect')
                                ->label('Select Unit of Measure')
                                ->live() // Agar tabel refresh saat diubah
                                // ==========================================================
                                // --- INI PERBAIKANNYA ---
                                // Membuat Opsi UoM Dinamis
                                // ==========================================================
                                ->options(function () use ($livewire): array {
                                    // 1. Ambil PickingList dan muat relasi item->produk->uoms
                                    $pickingList = $livewire->getOwnerRecord()->load('items.product.uoms');

                                    // 2. Kumpulkan semua UoM unik dari semua produk
                                    $availableUoms = $pickingList->items
                                        ->pluck('product.uoms') // Ambil koleksi UoM per produk
                                        ->flatten()            // Gabungkan jadi satu koleksi
                                        ->whereNotNull()       // Hapus produk tanpa UoM
                                        ->pluck('uom_name')    // Ambil nama UoM saja
                                        ->unique()             // Hanya ambil yang unik
                                        ->sort()               // Urutkan
                                        ->values()             // Reset keys
                                        ->all();               // Ubah jadi array biasa

                                    // 3. Buat array opsi untuk Select
                                    $options = ['base' => 'Base UoM']; // Selalu ada opsi Base UoM
                                    foreach ($availableUoms as $uom) {
                                        // Jangan tambahkan 'base' lagi jika ada di daftar
                                        $baseUomName = $pickingList->items->first()?->product?->base_uom ?? 'PCS';
                                        if ($uom !== $baseUomName) {
                                            $options[$uom] = $uom; // Gunakan nama UoM sebagai key dan value
                                        }
                                    }
                                    return $options;
                                })
                                ->default($livewire->displayUom)
                                ->afterStateUpdated(fn ($livewire, $state) => $livewire->displayUom = $state),
                        ];
                    })
            ])
            ->actions([
                    Tables\Actions\EditAction::make()
                    ->label('Input Picked Qty')
                    ->visible(function() {
                        // Ambil picking list (parent) & user saat ini di dalam closure
                        $pickingList = $this->getOwnerRecord();
                        $user = Auth::user();
                        $userId = $user?->id;

                        // Lakukan pengecekan yang sama seperti sebelumnya
                        $isVisible = $pickingList instanceof PickingList &&
                                     $pickingList->status === 'in_progress' &&
                                     $pickingList->user_id == $userId;

                        // (Logging bisa dihapus jika sudah yakin)
                        Log::info("Visible Check (Inside Closure) - PL ID: {$pickingList->id}, Status: {$pickingList->status}, Assigned: {$pickingList->user_id}, Current: {$userId}, Result: " . ($isVisible ? 'Visible' : 'Hidden'));

                        return $isVisible;
                     })
                     ->mutateFormDataUsing(function (array $data, Model $record): array {
                        // Logika konversi input UoM (tetap sama)
                        $inputQty = (float)($data['input_quantity'] ?? 0);
                        $inputUomName = $data['input_uom'] ?? null;
                        $allocatedQty = (float)$record->total_quantity_to_pick;

                        if ($inputQty < 0) {
                             throw \Illuminate\Validation\ValidationException::withMessages([ 'input_quantity' => 'Quantity cannot be negative.' ]);
                        }
                        if (!$inputUomName) {
                             throw \Illuminate\Validation\ValidationException::withMessages([ 'input_uom' => 'Please select the unit of measure.' ]);
                        }

                        $record->loadMissing('product.uoms');
                        $inputUom = $record->product?->uoms->where('uom_name', $inputUomName)->first();

                        if (!$inputUom) {
                            throw \Illuminate\Validation\ValidationException::withMessages([ 'input_uom' => 'Selected UoM data not found for this product.' ]);
                        }

                        $conversionRate = $inputUom->conversion_rate ?? 1;
                        $calculatedQtyPicked = $inputQty * $conversionRate;

                        if ($calculatedQtyPicked > $allocatedQty) {
                             throw \Illuminate\Validation\ValidationException::withMessages([ 'input_quantity' => "Quantity picked ({$calculatedQtyPicked} in base UoM) cannot exceed allocated quantity ({$allocatedQty} in base UoM)." ]);
                        }

                        $data['quantity_picked'] = $calculatedQtyPicked;

                        unset($data['input_uom']);
                        unset($data['input_quantity']);
                        unset($data['product_name']);
                        // Hapus juga placeholder display agar tidak coba disimpan
                        unset($data['total_quantity_to_pick_display']);

                        return $data;
                    // ==========================================================
                    // --- TAMBAHKAN LOGGING DI SINI ---
                    // ==========================================================
                    // ->visible(function() use ($pickingList, $user, $userId) { // Teruskan variabel
                    //     if (!$pickingList instanceof PickingList || !$user) {
                    //         Log::warning("Visible Check Failed: PickingList or User object is invalid.");
                    //         return false; // Validasi objek
                    //     }

                    //     $statusIsInProgress = $pickingList->status === 'in_progress';
                    //     $userIdMatches = $pickingList->user_id == $userId; // Gunakan == untuk tipe data

                    //     Log::info("Visible Check - PickingList ID: {$pickingList->id}, Status: {$pickingList->status}, Assigned User: {$pickingList->user_id}, Current User: {$userId}");
                    //     Log::info("Visible Check - Status is 'in_progress'? " . ($statusIsInProgress ? 'Yes' : 'No'));
                    //     Log::info("Visible Check - User ID matches? " . ($userIdMatches ? 'Yes' : 'No'));

                    //     $isVisible = $statusIsInProgress && $userIdMatches;
                    //     Log::info("Visible Check - Result: " . ($isVisible ? 'Visible' : 'Hidden'));
                    //     return $isVisible;
                    //  }),
            })
                     // Mengisi form saat modal dibuka (opsional, jika ingin load data lama)
                    ->fillForm(function (Model $record): array {
                        // Logika fill form (tetap sama)
                         $record->loadMissing('product');
                         $defaultUom = $record->product?->base_uom ?? 'PCS';
                         $qtyPickedBase = $record->quantity_picked; // Ambil nilai tersimpan (base)
                         $defaultQtyInput = 0; // Default input

                         // Jika sudah ada nilai tersimpan, coba konversi ke UoM yang dipilih user ($this->displayUom)
                         // atau ke UoM terbesar jika belum dipilih
                         $displayUomToShow = ($this->displayUom !== 'base') ? $this->displayUom : $defaultUom;
                         $targetUomData = $record->product?->uoms->where('uom_name', $displayUomToShow)->first();

                         if ($qtyPickedBase !== null && $targetUomData && $targetUomData->conversion_rate > 0) {
                             $defaultQtyInput = round($qtyPickedBase / $targetUomData->conversion_rate, 2);
                             $defaultUom = $displayUomToShow; // Set UoM default ke UoM Tampilan
                         } elseif ($qtyPickedBase !== null) {
                             $defaultQtyInput = $qtyPickedBase; // Jika gagal konversi, tampilkan base
                         }

                         return [
                             // 'total_quantity_to_pick' tidak perlu di-fill karena Placeholder mengambil dari record
                             'input_uom' => $defaultUom,
                             'input_quantity' => $defaultQtyInput,
                             // Bawa nilai asli jika perlu (misal untuk validasi ulang)
                             'quantity_picked' => $record->quantity_picked,
                         ];
                     }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
