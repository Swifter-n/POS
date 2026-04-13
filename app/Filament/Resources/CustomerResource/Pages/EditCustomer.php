<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

        public function mount(int | string $record): void
    {
        parent::mount($record);

        $customer = $this->getRecord();

        // Jika customer memiliki village_id
        if ($customer->village_id) {
            $village = $customer->village;
            $district = $village?->district;
            $regency = $district?->regency;
            $province = $regency?->province;

            $addressData = [
                'province_id' => $province?->id,
                'regency_id' => $regency?->id,
                'district_id' => $district?->id,
            ];

            $this->form->fill(array_merge($this->form->getState(), $addressData));
        }
    }
}
