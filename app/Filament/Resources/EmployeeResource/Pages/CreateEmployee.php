<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;


    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Tetapkan business_id dari user yang sedang login (owner)
        $data['business_id'] = Auth::user()->business_id;
        if (empty($data['locationable_id'])) {
            $data['locationable_type'] = null;
            $data['locationable_id'] = null;
        }
        // --- AKHIR BAGIAN PENTING ---

        return $data;
    }

}
