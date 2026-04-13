<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

 protected function mutateFormDataBeforeFill(array $data): array
    {
        // Pecah data polimorfik menjadi dua field form
        $data['locationable_type'] = $this->getRecord()->locationable_type;
        $data['locationable_id'] = $this->getRecord()->locationable_id;

        // ==========================================================
        // --- PERBAIKAN: Tambahkan baris ini ---
        // ==========================================================
        // Ambil 'plant_id' dari record dan masukkan ke form
        $data['plant_id'] = $this->getRecord()->plant_id;
        // ==========================================================

        return $data;
    }
}
