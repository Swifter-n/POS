<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StockTransferResource;
use App\Filament\Resources\WarehouseTaskResource;
use App\Models\GoodsReceipt;
use App\Models\Shipment;
use App\Models\StockTransfer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class MyPutAwayTasks extends BaseWidget
{
    protected static ?string $heading = 'My Put-Away Tasks (Inbound)';
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                StockTransfer::query()
                    ->where('business_id', Auth::user()->business_id)
                    ->where('assigned_user_id', Auth::id())
                    ->where('transfer_type', 'put_away')
                    ->where('status', 'pending_pick')
            )
            ->columns([
                Tables\Columns\TextColumn::make('transfer_number'),
                Tables\Columns\TextColumn::make('source_document')
                    ->label('Source Doc')
                    ->getStateUsing(function (StockTransfer $record): ?string {
                        // $record->loadMissing('sourceable'); // (Sudah di-eager load)
                        if ($record->sourceable instanceof GoodsReceipt) {
                            return $record->sourceable->receipt_number . ' (GRN)';
                        }
                        if ($record->sourceable instanceof StockTransfer) {
                            // Ini adalah task dari STO (QI/STG)
                            return $record->sourceable->transfer_number . ' (STO)';
                        }
                        // [BARU] Tambahkan cek untuk Shipment
                        if ($record->sourceable instanceof Shipment) {
                            return $record->sourceable->shipment_number . ' (DO Cancel)';
                        }
                        return 'N/A';
                    })
                    ->searchable(
                        // Izinkan search di ketiga relasi
                        query: fn (Builder $query, string $search) =>
                            $query->whereHas('sourceable', fn (Builder $q) =>
                                $q->where('receipt_number', 'like', "%{$search}%")
                            )
                            ->orWhereHas('sourceable', fn (Builder $q) =>
                                $q->where('transfer_number', 'like', "%{$search}%")
                            )
                            // [BARU] Tambahkan search untuk shipment_number
                            ->orWhereHas('sourceable', fn (Builder $q) =>
                                $q->where('shipment_number', 'like', "%{$search}%")
                            )
                    ),
                Tables\Columns\TextColumn::make('sourceLocation.name')->label('From Location'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->label('Assigned At'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending_put_away',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('view_task')
                    ->label('View & Execute Task')
                    ->icon('heroicon-o-arrow-right')
                    ->url(fn (StockTransfer $record): string => WarehouseTaskResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('No put-away tasks assigned')
            ->emptyStateDescription('You have no inbound tasks assigned to you at the moment.');
    }
}
