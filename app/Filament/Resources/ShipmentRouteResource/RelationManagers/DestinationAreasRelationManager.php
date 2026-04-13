<?php

namespace App\Filament\Resources\ShipmentRouteResource\RelationManagers;

use App\Models\Area;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Actions\AttachAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class DestinationAreasRelationManager extends RelationManager
{
    protected static string $relationship = 'destinationAreas';
    protected static ?string $recordTitleAttribute = 'name';

public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('surcharge')
                    ->label('Surcharge (Biaya Tambahan)')
                    ->numeric()
                    ->prefix('Rp')
                    ->default(0)
                    ->required()
                    ->helperText('Biaya tambahan spesifik untuk area ini di rute ini.')
                    // ==========================================================
                    // --- TAMBAHKAN STRIP CHARACTERS ---
                    // ==========================================================
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(['R', 'p', ' ', '.', ',']),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name') // Ambil 'name' dari Area
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Area Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area_code') // Tampilkan kode Area jika ada
                    ->label('Area Code')
                    ->searchable()
                    ->sortable(),
                // Kolom 'surcharge' ini diambil dari data PIVOT
                Tables\Columns\TextColumn::make('surcharge')
                    ->money('IDR')
                    ->sortable()
                    ->label('Surcharge'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Add Destination Area')
                    ->form(fn (AttachAction $action): array => [
                        // 1. Dropdown untuk memilih Area
                        $action->getRecordSelect()
                            ->label('Area')
                            ->options(
                                // Ambil semua Area di bisnis user
                                Area::where('business_id', Auth::user()->business_id)
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        // 2. Field untuk data pivot (surcharge)
                        TextInput::make('surcharge')
                            ->label('Surcharge')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->required()
                            ->mask(RawJs::make('$money($input)'))
                            ->stripCharacters(['R', 'p', ' ', '.', ',']),
                    ])
                    // ==========================================================
                    // --- INI PERBAIKANNYA: Gunakan mutateFormDataUsing ---
                    // ==========================================================
                    ->mutateFormDataUsing(function (array $data): array {
                        // 1. Pastikan data 'surcharge' adalah angka bersih
                        if (isset($data['surcharge']) && is_string($data['surcharge'])) {
                             $data['surcharge'] = (float) preg_replace('/[Rp, .]/', '', $data['surcharge']);
                        }

                        // 2. Tambahkan business_id ke data pivot
                        if (Auth::check()) {
                            $data['business_id'] = Auth::user()->business_id;
                        }

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    // Pastikan EditAction juga mengisi data pivot dengan benar
                    ->mutateRecordDataUsing(function (Model $record, array $data): array {
                         $data['surcharge'] = $record->pivot->surcharge; // Ambil nilai pivot
                         return $data;
                    }),
                Tables\Actions\DetachAction::make(), // Untuk menghapus Area dari Rute
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
