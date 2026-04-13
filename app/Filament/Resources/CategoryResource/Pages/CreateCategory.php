<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    // protected function mutateFormDataBeforeCreate(array $data): array
    // {
    //     // Ambil business_id dari user yang sedang login
    //     $businessId = Auth::user()->business_id;

    //     // Tambahkan business_id ke dalam array data form
    //     $data['business_id'] = $businessId;

    //     // Kembalikan array data yang sudah dimodifikasi
    //     return $data;
    // }
}
