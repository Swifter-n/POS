<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class GoodsReceiptController extends Controller
{
    use AuthorizesRequests;

    // Anda bisa membuat method index() untuk melihat riwayat GR jika perlu

    public function store(StoreGoodsReceiptRequest $request)
    {
        $data = $request->validated();
        $po = PurchaseOrder::find($data['purchase_order_id']);

        $receipt = DB::transaction(function () use ($data, $request, $po) {
            $receipt = GoodsReceipt::create([
                'receipt_number' => 'GR-' . date('Ym') . '-' . random_int(100, 999),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $data['warehouse_id'],
                'received_by_user_id' => $request->user()->id,
                'receipt_date' => $data['receipt_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $totalOrdered = $po->items()->sum('quantity_ordered');
            $totalReceived = 0;

            foreach ($data['items'] as $itemData) {
                if($itemData['quantity_received'] > 0) {
                    $receipt->items()->create($itemData);

                    $inventory = Inventory::firstOrCreate(
                        [
                            'stockable_id' => $data['warehouse_id'],
                            'stockable_type' => \App\Models\Warehouse::class,
                            'location_id' => $itemData['location_id'] ?? null,
                            'product_id' => $itemData['product_id'],
                            'batch' => $itemData['batch'] ?? null,
                            'sled' => $itemData['sled'] ?? null,
                        ],
                        ['avail_stock' => 0]
                    );

                    $inventory->increment('avail_stock', $itemData['quantity_received']);
                    InventoryMovement::create([
                        'inventory_id' => $inventory->id,
                        'quantity_change' => $itemData['quantity_received'],
                        'stock_after_move' => $inventory->avail_stock,
                        'type' => 'in',
                        'reference_type' => GoodsReceipt::class,
                        'reference_id' => $receipt->id,
                        'user_id' => $request->user()->id,
                    ]);
                }
                $totalReceived += $itemData['quantity_received'];
            }

            if ($totalReceived >= $totalOrdered) {
                $po->update(['status' => 'fully_received']);
            } elseif ($totalReceived > 0) {
                $po->update(['status' => 'partial_received']);
            }
            return $receipt;
        });

        return new GoodsReceiptResource($receipt->load('items'));
    }
}
