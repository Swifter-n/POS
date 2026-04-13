<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PickingListResource;
use App\Models\PickingList;
use App\Models\SalesOrder;
use App\Models\StockTransfer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class MyPickingTasks extends BaseWidget
{
    // 2. Kembalikan heading
    protected static ?string $heading = 'My Picking Tasks (Outbound)';
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // 3. Kembalikan query ke PickingList
                PickingList::query()
                    ->with(['sourceable']) // Eager load relasi
                    ->where('user_id', Auth::id())
                    ->where('status', 'pending')
            )
            ->columns([
                Tables\Columns\TextColumn::make('picking_list_number'),

                // 4. INI ADALAH PERBAIKAN "GABUNGKAN SO/STO"
                //    Kolom cerdas untuk menampilkan SO atau STO
                Tables\Columns\TextColumn::make('source_document')
                    ->label('From SO / STO')
                    ->getStateUsing(function (PickingList $record) {
                        // Cek tipe sourceable
                        if ($record->sourceable_type === SalesOrder::class) {
                            // Jika SalesOrder, tampilkan so_number
                            return $record->sourceable?->so_number ?? 'Sales Order';
                        }
                        if ($record->sourceable_type === StockTransfer::class) {
                             // Jika STO, tampilkan transfer_number (dari STO aslinya)
                            return $record->sourceable?->transfer_number ?? 'STO';
                        }
                        return 'N/A';
                    })
                    ->searchable(
                        // Aktifkan search di relasi sourceable
                        query: fn ($query, $search) =>
                            $query->whereHasMorph('sourceable', [
                                SalesOrder::class,
                                StockTransfer::class
                            ], fn ($q) => $q->where('so_number', 'like', "%{$search}%")
                                          ->orWhere('transfer_number', 'like', "%{$search}%"))
                    ),

                Tables\Columns\TextColumn::make('created_at')->dateTime()->label('Assigned At'),
            ])
            ->actions([
                // 5. Kembalikan Action ke PickingListResource
                Tables\Actions\Action::make('view_task')
                    ->label('View & Start Task')
                    ->icon('heroicon-o-arrow-right')
                    ->url(fn (PickingList $record): string => PickingListResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No picking tasks assigned')
            ->emptyStateDescription('You have no outbound tasks assigned to you at the moment.');
    }
}
