<?php

namespace App\Filament\Resources\BusinessResource\Pages;

use App\Filament\Resources\BusinessResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBusiness extends CreateRecord
{
    protected static string $resource = BusinessResource::class;
    // protected function mutateFormDataBeforeCreate(array $data): array
    // {
    //     // Ambil business_id dari user yang sedang login
    //     $userId = Auth::user()->id;

    //     // Tambahkan business_id ke dalam array data form
    //     $data['user_id'] = $userId;

    //     // Kembalikan array data yang sudah dimodifikasi
    //     return $data;
    // }
}
