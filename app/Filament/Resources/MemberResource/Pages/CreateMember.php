<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['business_id'] = Auth::user()->business_id;

        // Generate QR Token otomatis jika admin create manual
        if (empty($data['qr_token'])) {
             $data['qr_token'] = 'MEM-' . time() . '-' . Str::random(5);
        }

        // Password dummy
        $data['password'] = Hash::make('123456');

        return $data;
    }
}
