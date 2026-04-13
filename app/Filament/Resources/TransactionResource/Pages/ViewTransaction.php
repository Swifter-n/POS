<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Infolists; // <-- Gunakan namespace Infolists
use Filament\Infolists\Infolist;
use Illuminate\Contracts\Support\Htmlable;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Tombol Edit akan otomatis muncul jika Anda punya EditOrder.php
            Actions\EditAction::make(),
            // Tombol kembali ke daftar
            Actions\Action::make('back')
                ->label('Back to List')
                ->color('gray')
                ->url(static::getResource()::getUrl('index'))
                ->icon('heroicon-o-arrow-left'),
        ];
    }

    /**
     * Halaman ViewRecord menggunakan method infolist() untuk mendefinisikan
     * bagaimana data akan ditampilkan.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->getRecord()->loadMissing('promoCode')) // <-- Muat relasi promoCode
            ->schema([
                Infolists\Components\Section::make('Order Details')
                    ->schema([
                        Infolists\Components\Grid::make(3)->schema([
                            Infolists\Components\TextEntry::make('order_number'),
                            Infolists\Components\TextEntry::make('outlet.name'),
                            Infolists\Components\TextEntry::make('created_at')->dateTime('d M Y H:i:s'),
                        ]),
                    ]),
                Infolists\Components\Section::make('Customer & Payment')
                    ->schema([
                        Infolists\Components\Grid::make(3)->schema([
                            Infolists\Components\TextEntry::make('customer_name'),
                            Infolists\Components\TextEntry::make('payment_method')->badge(),
                            Infolists\Components\TextEntry::make('status')->badge()
                                ->color(fn (string $state): string => match (strtolower($state)) { // <-- Gunakan strtolower
                                    'success', 'paid', 'settled' => 'success',
                                    'pending', 'unpaid', 'open' => 'warning',
                                    'failed', 'expired', 'cancelled' => 'danger',
                                    default => 'gray',
                                }),
                        ]),
                    ]),
                Infolists\Components\Section::make('Financial Summary')
                    ->schema([
                        // ==========================================================
                        // --- PERBAIKAN: Buat Grid 5 Kolom & Tambahkan Promo Code ---
                        // ==========================================================
                        Infolists\Components\Grid::make(5)->schema([
                            Infolists\Components\TextEntry::make('sub_total')->money('IDR'),

                            Infolists\Components\TextEntry::make('promoCode.code')
                                ->label('Promo Code Applied')
                                ->badge()
                                ->color('success')
                                ->placeholder('N/A'), // Tampil 'N/A' jika tidak ada promo

                            Infolists\Components\TextEntry::make('discount')->money('IDR'),
                            Infolists\Components\TextEntry::make('tax')->money('IDR'),
                            Infolists\Components\TextEntry::make('total_price')->label('Grand Total')->money('IDR')->weight('bold'),
                        ]),
                    ]),
                Infolists\Components\Section::make('Order Items')
                    ->schema([
                        // Gunakan RepeatableEntry untuk menampilkan relasi 'items'
                        Infolists\Components\RepeatableEntry::make('items')
                            ->label(null) // Hilangkan label "Items"
                            ->schema([
                                Infolists\Components\TextEntry::make('product.name')->label('Product')->columnSpan(2),
                                Infolists\Components\TextEntry::make('quantity')->numeric(),
                                Infolists\Components\TextEntry::make('price')->label('Final Price / item')->money('IDR'),
                                Infolists\Components\TextEntry::make('total')->money('IDR'),
                            ])
                            ->columns(5)
                            ->grid(1), // Ubah ke 1 agar item menumpuk
                    ]),
            ]);
    }
}
