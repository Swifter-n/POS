<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TransactionResource;
use App\Models\Order;
use App\Models\Outlet;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class LatestOrders extends BaseWidget
{
protected static ?int $sort = 3; // Tampilkan di bawah
    protected int | string | array $columnSpan = 'full'; // Lebar penuh

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $user = Auth::user();
                $query = TransactionResource::getEloquentQuery(); // <-- Meminjam query dari Resource

                // Jika user adalah staff outlet, set default outlet
                if ($user->locationable_type === Outlet::class) {
                    $query->where('outlet_id', $user->locationable_id);
                }

                return $query->latest()->limit(5); // Ambil 5 terbaru
            })
            ->columns([
                Tables\Columns\TextColumn::make('order_number'),
                Tables\Columns\TextColumn::make('outlet.name')
                    ->visible(Auth::user()->hasRole('owner')), // Hanya Owner yg perlu lihat kolom ini
                Tables\Columns\TextColumn::make('customer_name'),
                Tables\Columns\TextColumn::make('total_price')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->colors([
                        'success' => fn($state): bool => in_array(strtolower($state), ['success', 'paid', 'settled']),
                        'warning' => fn($state): bool => in_array(strtolower($state), ['pending', 'unpaid', 'open']),
                        'danger' => fn($state): bool => in_array(strtolower($state), ['failed', 'expired', 'cancelled']),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn ($record): string => TransactionResource::getUrl('view', ['record' => $record])),
                Tables\Actions\EditAction::make()
                     ->url(fn ($record): string => TransactionResource::getUrl('edit', ['record' => $record]))
                     ->visible(fn ($record): bool => in_array(strtolower($record->status), ['pending', 'open', 'unpaid'])),
            ]);
    }
}
