<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

     /**
     * Override method ini untuk menambahkan data default sebelum create.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Langsung tambahkan/timpa key dengan nilai default 0.
        $data['current_balance'] = 0;
        $data['total_order_count'] = 0;
        $data['total_spend'] = 0;

        // Tambahkan business_id dari user yang login.
        $data['business_id'] = Auth::user()->business_id;

        return $data;
    }

}
