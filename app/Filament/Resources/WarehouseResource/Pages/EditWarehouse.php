<?php

namespace App\Filament\Resources\WarehouseResource\Pages;

use App\Filament\Resources\WarehouseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWarehouse extends EditRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

           /**
     * Override method mount untuk mengisi data dropdown alamat.
     */
    // public function mount(int | string $record): void
    // {
    //     parent::mount($record);

    //     // Ambil data warehouse yang sedang diedit
    //     $warehouse = $this->getRecord();

    //     // Jika warehouse warehouse memiliki village_id (alamat tersimpan)
    //     if ($warehouse->village_id) {
    //         // Ambil data kelurahan, kecamatan, kota, dan provinsi melalui relasi
    //         $village = $warehouse->village;
    //         $district = $village?->district;
    //         $regency = $district?->regency;
    //         $province = $regency?->province;

    //         // Siapkan data untuk diisi ke form
    //         $addressData = [
    //             'province_id' => $province?->id,
    //             'regency_id' => $regency?->id,
    //             'district_id' => $district?->id,
    //         ];

    //         // Gabungkan dengan data form yang sudah ada, lalu isi kembali
    //         $this->form->fill(array_merge($this->form->getState(), $addressData));
    //     }
    // }
}
