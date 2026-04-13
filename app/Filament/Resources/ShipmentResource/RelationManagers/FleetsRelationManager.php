<?php

namespace App\Filament\Resources\ShipmentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\AttachAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FleetsRelationManager extends RelationManager
{
    protected static string $relationship = 'fleets';

    public function form(Form $form): Form
    {
        // Form ini untuk mengedit data pivot (driver_name, status, notes)
        // setelah armada di-attach.
        return $form
            ->schema([
                Forms\Components\TextInput::make('driver_name')->required(),
                Forms\Components\Select::make('status')
                ->options([
                    'loading' => 'Loading',
                    'shipping' => 'Shipping',
                    'delivered' => 'Delivered',
                    'cancelled' => 'Cancelled',
                ])
                ->required()
                ->default('loading'),
                Forms\Components\Textarea::make('notes')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('plate_number')
            ->columns([
                Tables\Columns\TextColumn::make('vehicle_name'),
                Tables\Columns\TextColumn::make('plate_number'),
                Tables\Columns\TextColumn::make('pivot.driver_name')->label('Driver'), // Menampilkan data pivot
                Tables\Columns\TextColumn::make('pivot.status')->label('Task Status')->badge() // Menampilkan data pivot
                    ->colors(['primary' => 'loading', 'info' => 'shipping', 'success' => 'delivered', 'danger' => 'cancelled']),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                // ==========================================================
                // --- SOLUSI ALTERNATIF (KEMBALI KE SELECT MANUAL + FIX) ---
                // ==========================================================
                Tables\Actions\AttachAction::make()
                    ->form(fn (AttachAction $action): array => [
                        // 1. Buat Select manual
                        Forms\Components\Select::make('recordId')
                            ->label('Vehicle')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                /** @var Shipment $shipment */
                                $shipment = $this->getOwnerRecord();

                                // Ambil query dasar untuk Fleet
                                $query = \App\Models\Fleet::query();

                                // ==========================================================
                                // --- PERBAIKAN: "status" is ambiguous ---
                                // ==========================================================

                                // 1. Tentukan tabel 'fleets' secara eksplisit
                                $query->where('fleets.status', 'available'); // <-- FIX 1

                                // 2. Tentukan tabel 'shipments' di dalam subquery
                                $scheduledDate = $shipment->scheduled_for ? $shipment->scheduled_for->format('Y-m-d') : now()->format('Y-m-d');

                                $query->whereDoesntHave('shipments', function (Builder $subQuery) use ($scheduledDate) {
                                    $subQuery->whereDate('shipments.scheduled_for', $scheduledDate) // <-- FIX 2
                                            ->whereIn('shipments.status', ['ready_to_ship', 'shipping']); // <-- FIX 2
                                });
                                // ==========================================================

                                return $query->pluck('vehicle_name', 'id');
                            }),

                        // 2. Tambahkan field pivot Anda
                        Forms\Components\TextInput::make('driver_name')->required(),
                        Forms\Components\TextInput::make('status')
                            ->default('loading') // <-- Default saat attach adalah 'loading'
                            ->helperText("Status tugas untuk armada ini (cth: 'loading')")
                            ->required(),
                        Forms\Components\Textarea::make('notes')->columnSpanFull(),
                    ]),
                // =LAGI)
            ])
            ->actions([
                Tables\Actions\EditAction::make(), // Izinkan edit data pivot (driver, status)
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}
