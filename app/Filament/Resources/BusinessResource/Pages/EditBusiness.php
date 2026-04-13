<?php

namespace App\Filament\Resources\BusinessResource\Pages;

use App\Filament\Resources\BusinessResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBusiness extends EditRecord
{
    protected static string $resource = BusinessResource::class;

    // protected function mutateFormDataBeforeSave(array $data): array
    // {
    //     // Tetapkan user_id dengan ID user yang sedang mengedit
    //     $data['user_id'] = Auth::user()->id;

    //     return $data;
    // }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }


}
