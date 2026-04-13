<?php

namespace App\Filament\Resources\PurchaseReturnResource\Pages;

use App\Events\PurchaseReturnCompleted;
use App\Filament\Resources\PurchaseReturnResource;
use App\Models\InventoryMovement;
use App\Models\PurchaseReturn;
use App\Models\Warehouse;
use App\Traits\HasPermissionChecks;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EditPurchaseReturn extends EditRecord
{
    use HasPermissionChecks;
    protected static string $resource = PurchaseReturnResource::class;


    public function form(Form $form): Form
    {
        $record = $this->getRecord();
        // Muat relasi yang diperlukan untuk Placeholder
        $record->loadMissing('plant', 'warehouse', 'supplier', 'purchaseOrder');

        return $form->schema([
            Section::make('Purchase Return Details')
                ->schema([
                    Placeholder::make('return_number')->content($record->return_number),
                    Placeholder::make('plant')->content($record->plant?->name),
                    Placeholder::make('warehouse')->content($record->warehouse?->name),
                    Placeholder::make('supplier_id')->label('Supplier')->content($record->supplier?->name),
                    Placeholder::make('purchase_order_id')->label('Reference PO')->content($record->purchaseOrder?->po_number ?? 'N/A'),
                    Placeholder::make('status')->content(ucfirst($record->status)),
                    Placeholder::make('notes')->content($record->notes)->columnSpanFull(),
                ])->columns(3),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $user = Auth::user();
        $record = $this->getRecord();

        return [
           Actions\Action::make('approveAndShipReturn')
                ->label('Approve & Ship Return')
                ->color('success')->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Approve and Process Return')
                ->modalDescription('Are you sure you want to process this return? Stock will be permanently removed from inventory.')
                ->visible(fn () => $record->status === 'draft' && $this->check($user, 'approve purchase returns'))
                ->action(function (PurchaseReturn $record) {
                    try {
                        DB::transaction(function () use ($record) {
                            // 1. Muat relasi item (sudah termasuk product dan inventory)
                            $record->load(
                                'items.product', // Diperlukan untuk pesan error
                                'items.inventory.location' // Diperlukan untuk notes movement
                            );

                            if ($record->items->isEmpty()) {
                                throw new \Exception('Cannot process: No items have been added to this return.');
                            }

                            $userId = Auth::id();

                            // 2. Loop setiap item yang di-return
                            foreach($record->items as $item) {
                                $inventory = $item->inventory;
                                if (!$inventory) {
                                     throw new \Exception("Invalid inventory record (ID: {$item->inventory_id}) for item {$item->product?->name}.");
                                }

                                $quantityToMove = (float) $item->quantity_base_uom;

                                // 4. Validasi Stok Ulang (Safety Check)
                                if (round($inventory->avail_stock, 5) < round($quantityToMove, 5)) {
                                    throw new \Exception("Insufficient stock for {$item->product?->name} (Batch {$inventory->batch}). Available: {$inventory->avail_stock}, Required: {$quantityToMove}");
                                }

                                // 5. KURANGI STOK DARI SUMBER (DMG/QI/RET)
                                $inventory->decrement('avail_stock', $quantityToMove);

                                InventoryMovement::create([
                                    'inventory_id' => $inventory->id,
                                    'quantity_change' => -$quantityToMove, // Negatif karena keluar
                                    'stock_after_move' => $inventory->avail_stock,
                                    'type' => 'PURCHASE_RETURN_OUT', // Tipe baru
                                    'reference_type' => get_class($record),
                                    'reference_id' => $record->id,
                                    'user_id' => $userId,
                                    'notes' => "Return to supplier from {$inventory->location?->name}"
                                ]);
                            } // Akhir loop items

                            // 6. Update status (termasuk 'approved_by_user_id')
                            $record->update([
                                'status' => 'shipped', // Asumsi 'shipped' adalah status setelah barang keluar
                                'approved_by_user_id' => $userId // <-- Kolom ini sekarang ada
                            ]);
                        });

                        $record->loadMissing('items.product', 'supplier');

                        // 2. Panggil (dispatch) event-nya
                        PurchaseReturnCompleted::dispatch($record);

                        Notification::make()->title('Purchase Return processed successfully.')->success()->send();
                        return redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                        //$this->refreshFormData($this->getRecord()->toArray()); // Refresh form

                    } catch (\Exception $e) {
                        Notification::make()->title('Return Failed')->body($e->getMessage())->danger()->send();
                        $this->halt();
                    }
                }),

            Actions\DeleteAction::make()
                ->visible(fn() => $record->status === 'draft' && $this->check($user, 'delete purchase returns')),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

}
