<?php

namespace App\Filament\Resources\PlantResource\Pages;

use App\Filament\Resources\PlantResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePlant extends CreateRecord
{
    protected static string $resource = PlantResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
        {
            // Pastikan user login sebelum mengambil ID bisnis
            if (Auth::check()) {
                $data['business_id'] = Auth::user()->business_id;
            } else {
                // Handle kasus jika user tidak login (misalnya lempar error atau set default)
                throw new \Exception("User must be logged in to create a plant.");
                // Atau set ke null jika kolom business_id nullable dan ada fallback logic
                // $data['business_id'] = null;
            }

            // Anda bisa menambahkan data default lain di sini jika perlu
            //$data['status'] = true; // Contoh

            return $data;
        }
}
