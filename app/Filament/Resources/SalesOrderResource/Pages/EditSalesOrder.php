<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Models\InventoryMovement;
use App\Models\SalesOrder;
use App\Models\Shipment;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;
    use HasPermissionChecks;

    protected $listeners = [
        'soTotalsUpdated' => 'refreshFormTotals',
    ];

    /**
     * Method ini akan dipanggil oleh listener.
     */
    public function refreshFormTotals(): void
    {
        // 1. Muat ulang data terbaru dari database
        $this->getRecord()->refresh();

        // 2. Isi ulang (refresh) form header dengan data baru
        $this->refreshFormData([
            'sub_total',
            'total_discount',
            'tax',
            'grand_total',
        ]);
    }

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        return [
             /**
             * AKSI PEMBATALAN SALES ORDER LENGKAP
             */
            Actions\Action::make('cancelSalesOrder')
                ->label('Cancel Order')
                ->color('danger')->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                // Tombol ini tidak akan muncul jika SO sudah selesai (diterima) atau sudah dibatalkan
                ->visible(fn (SalesOrder $record) =>
                    !in_array($record->status, ['received', 'cancelled']) &&
                    $this->check(Auth::user(), 'cancel sales orders')
                )
                ->action(function (SalesOrder $record) {
                    try {
                        DB::transaction(function () use ($record) {
                            $wasApprovedOrFurther = in_array($record->getOriginal('status'), ['approved', 'processing', 'ready_to_ship', 'shipped']);

                            // Ambil dokumen terkait yang mungkin aktif
                            $shipment = $record->shipments()->whereNotIn('status', ['cancelled', 'received'])->first();

                            // ==========================================================
                            // SKENARIO 1: JIKA BARANG SUDAH DI JALAN (STATUS 'shipping')
                            // ==========================================================
                            if ($shipment && $shipment->status === 'shipping') {
                                // 1A. Kembalikan stok yang sudah terlanjur dikurangi
                                $outboundMovements = InventoryMovement::where('reference_type', Shipment::class)
                                    ->where('reference_id', $shipment->id)
                                    ->where('quantity_change', '<', 0)->get();

                                foreach ($outboundMovements as $movement) {
                                    $inventory = $movement->inventory;
                                    if ($inventory) {
                                        $quantityToReturn = abs($movement->quantity_change);
                                        $inventory->increment('avail_stock', $quantityToReturn);

                                        // 1B. BUAT LOG PENGEMBALIAN STOK (RETURN LOG)
                                        InventoryMovement::create([
                                            'inventory_id' => $inventory->id,
                                            'quantity_change' => $quantityToReturn, // Positif karena menambah
                                            'stock_after_move' => $inventory->avail_stock,
                                            'type' => 'RETURN_SALE_CANCEL',
                                            'reference_type' => SalesOrder::class,
                                            'reference_id' => $record->id,
                                            'user_id' => Auth::id(),
                                            'notes' => 'Stock returned from cancelled SO: ' . $record->so_number,
                                        ]);
                                    }
                                }

                                // 1C. Bebaskan kembali armada yang bertugas
                                $shipment->fleets()->update(['status' => 'available']);
                            }

                            // ==========================================================
                            // PROSES UMUM UNTUK SEMUA PEMBATALAN
                            // ==========================================================

                            // 2. Batalkan semua dokumen terkait yang aktif (Picking List & Shipment)
                            $record->pickingLists()->whereNotIn('status', ['cancelled'])->update(['status' => 'cancelled']);
                            $record->shipments()->whereNotIn('status', ['cancelled', 'received'])->update(['status' => 'cancelled']);

                            // 3. Batalkan Sales Order itu sendiri
                            $record->update(['status' => 'cancelled']);

                            // 4. Kembalikan Credit Limit jika pembayaran kredit & SO sudah di-approve sebelumnya
                            if ($record->payment_type === 'credit' && $wasApprovedOrFurther) {
                                $record->customer->decrement('current_balance', $record->grand_total);
                            }
                        });

                        Notification::make()->title('Sales Order Cancelled Successfully.')->success()->send();
                    } catch (\Exception $e) {
                        Notification::make()->title('Failed to cancel Sales Order')->body($e->getMessage())->danger()->send();
                    }
                }),
           //Actions\DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        // Kembalikan array kosong untuk menyembunyikan semua tombol footer
        return [];
    }
}
