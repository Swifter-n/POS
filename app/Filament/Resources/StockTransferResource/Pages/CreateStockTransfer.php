<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Plant;
use App\Models\Warehouse;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

   public function form(Form $form): Form
    {
         return $form
            ->schema([
                Section::make('Transfer Details')->schema([
                   Select::make('transfer_type')
                            ->label('Select Transfer Type')
                            ->options([
                                'internal' => 'Internal (Within same Plant/WH)',
                                'external_plant' => 'External (Plant to Plant/DC)',
                                'external_outlet' => 'External (Plant to Outlet)',
                            ])
                            ->required()
                            ->live()
                            ->default('external_plant')
                            ->afterStateUpdated(function(Set $set){
                                // Reset pilihan sumber/tujuan saat tipe berubah
                                $set('source_plant_id', null);
                                $set('destination_plant_id', null);
                                $set('destination_outlet_id', null);
                                $set('internal_plant_id', null);
                                $set('source_location_id', null);
                                $set('destination_location_id', null);
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
                                    'locatable',
                                    [Warehouse::class, Outlet::class],
                                    function(Builder $q, string $type) use ($plantId) {
                                        if ($type === Warehouse::class) {
                                            $q->where('plant_id', $plantId);
                                        } elseif ($type === Outlet::class) {
                                            $q->where('supplying_plant_id', $plantId);
                                        }
                                    }
                                )
                                // [PERBAIKAN] Tambahkan 'QI'
                                ->whereIn('type', ['AREA', 'STAGING', 'QI'])
                                ->where('status', true)
                                ->get()
                                // [PERBAIKAN] Tambahkan Zone Code
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
                                    'locatable',
                                    [Warehouse::class, Outlet::class],
                                    function(Builder $q, string $type) use ($plantId) {
                                        if ($type === Warehouse::class) {
                                            $q->where('plant_id', $plantId);
                                        } elseif ($type === Outlet::class) {
                                            $q->where('supplying_plant_id', $plantId);
                                        }
                                    }
                                )
                                ->where('is_sellable', false) // (Tujuan tetap AREA)
                                ->whereIn('type', ['AREA'])     // (Tujuan tetap AREA)
                                ->where('status', true);

                                if ($sourceLocId) $query->where('id', '!=', $sourceLocId);
                                // [PERBAIKAN] Tambahkan Zone Code
                                return $query->get()->mapWithKeys(fn($loc) => [$loc->id => ($loc->locatable?->name ?? '') . ' > '. ($loc->zone?->code ?? 'N/A') .'> ' . $loc->name]);
                            })
                            ->searchable()->preload()
                            // ==========================================================
                            // --- PERBAIKAN: 'required' kondisional ---
                            // ==========================================================
                            ->required(function (Get $get): bool {
                                if ($get('transfer_type') !== 'internal') return false;

                                // Cek Skenario Put-Away (QI atau STG)
                                $sourceLoc = Location::find($get('source_location_id'));
                                if (!$sourceLoc) return true; // Wajibkan jika lokasi sumber belum dipilih

                                $sourceLoc->loadMissing('zone'); // Pastikan zona di-load

                                // TIDAK wajib jika sumbernya QI atau STG (karena akan jadi Put-Away Task)
                                return !in_array($sourceLoc?->zone?->code, ['QI', 'STG']);
                            })
                            ->visible(fn (Get $get) => $get('transfer_type') === 'internal'),

                        // --- Opsi untuk External Transfer (Plant/Outlet) ---
                        Select::make('source_plant_id')
                            ->label('Source Plant')
                            ->options(fn() => Plant::where('business_id', Auth::user()->business_id)->where('status', true)->pluck('name', 'id'))
                            ->searchable()->preload()->live()
                            ->required(fn (Get $get) => str_starts_with($get('transfer_type'), 'external'))
                            ->visible(fn (Get $get) => str_starts_with($get('transfer_type'), 'external')),
                        Select::make('destination_plant_id')
                            ->label('Destination Plant/DC')
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
                            ->label('Destination Outlet (Internal)') // <-- Label diubah
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
                        // Select::make('destination_outlet_id')
                        //     ->label('Destination Outlet')
                        //     ->options(function (Get $get) {
                        //         $query = Outlet::where('business_id', Auth::user()->business_id)->where('status', true);
                        //         return $query->pluck('name', 'id');
                        //     })
                        //     ->searchable()->preload()
                        //     ->required(fn (Get $get) => $get('transfer_type') === 'external_outlet')
                        //     ->visible(fn (Get $get) => $get('transfer_type') === 'external_outlet'),

                    DatePicker::make('request_date')->default(now())->required(),
                    Textarea::make('notes')->columnSpanFull(),
                ])->columns(2),
            ]);
    }

    /**
     * Mutasi data SEBELUM dikirim ke database.
     * (Logika Anda sebelumnya sudah benar)
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Tambahkan data default dari server
        $data['business_id'] = Auth::user()->business_id;
        $data['requested_by_user_id'] = Auth::id();
        $data['transfer_number'] = 'TRF-' . date('Ym') . '-' . random_int(1000, 9999);
        $data['status'] = 'draft';

        // 2. Jika Internal, set plant_id dari internal_plant_id
        if ($data['transfer_type'] === 'internal' && isset($data['internal_plant_id'])) {
             $data['plant_id'] = $data['internal_plant_id'];
        }
        // 3. Jika Eksternal, set plant_id dari source_plant_id
        elseif (str_starts_with($data['transfer_type'], 'external') && isset($data['source_plant_id'])) {
             $data['plant_id'] = $data['source_plant_id'];
        }

        // Hapus field form sementara
        unset($data['internal_plant_id']);

        return $data;
    }

    /**
     * Override handleRecordCreation untuk menggunakan forceCreate (jika $guarded di Model benar)
     * atau untuk memastikan data tersimpan.
     */
    protected function handleRecordCreation(array $data): Model
    {
        // $data sudah dimutasi oleh mutateFormDataBeforeCreate

        // Cukup panggil parent::handleRecordCreation
        // karena Model StockTransfer Anda sudah menggunakan $guarded = ['id']
        // yang berarti semua field di $data akan di-mass-assign.
        return parent::handleRecordCreation($data);
    }


    /**
     * Arahkan ke halaman Edit setelah header dibuat,
     * agar user bisa menambahkan item via Relation Manager.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

}
