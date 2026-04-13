<?php

namespace App\Filament\Resources\PlantResource\Pages;

use App\Filament\Resources\PlantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlant extends EditRecord
{
    protected static string $resource = PlantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

     public function mount(int | string $record): void
    {
        parent::mount($record);


        $plant = $this->getRecord();

        // Jika plant plant memiliki village_id (alamat tersimpan)
        if ($plant->village_id) {
            // Ambil data kelurahan, kecamatan, kota, dan provinsi melalui relasi
            $village = $plant->village;
            $district = $village?->district;
            $regency = $district?->regency;
            $province = $regency?->province;

            // Siapkan data untuk diisi ke form
            $addressData = [
                'province_id' => $province?->id,
                'regency_id' => $regency?->id,
                'district_id' => $district?->id,
            ];

            // Gabungkan dengan data form yang sudah ada, lalu isi kembali
            $this->form->fill(array_merge($this->form->getState(), $addressData));
        }
    }
}
