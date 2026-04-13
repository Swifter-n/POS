<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PickingItemResource;
use App\Http\Resources\PickingTaskResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PickingApiController extends Controller
{
    /**
     * GET: List Tugas Picking (User Specific)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PickingList::query()
            ->where('business_id', $user->business_id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with(['warehouse', 'sourceable']);

        // Filter Owner/Manager vs Staff
        if (!$user->hasRole('Owner') && !$user->hasRole('Manager Gudang')) {
            $query->where('user_id', $user->id);
        }

        $tasks = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => PickingTaskResource::collection($tasks)
        ]);
    }

    /**
     * GET: Detail Tugas & Instruksi FEFO
     */
    public function show($id)
    {
        $task = PickingList::with([
            'items.product',
            // Load deep relation untuk instruksi lokasi/batch
            'items.sources.inventory.location.zone'
        ])
        ->where('id', $id)
        ->firstOrFail();

        // Validasi Akses
        if ($task->business_id !== Auth::user()->business_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Auto-start status jika baru dibuka pertama kali (opsional)
        // if ($task->status === 'pending') {
        //      $task->update(['status' => 'in_progress', 'started_at' => now()]);
        // }

        return response()->json([
            'status' => 'success',
            'data' => [
                'task' => new PickingTaskResource($task),
                'items' => PickingItemResource::collection($task->items),
            ]
        ]);
    }

    public function start($id)
    {
        $task = PickingList::findOrFail($id);

        // Validasi Status
        if ($task->status === 'completed' || $task->status === 'cancelled') {
             return response()->json(['message' => 'Task cannot be started (Current status: ' . $task->status . ')'], 400);
        }

        // Hanya update jika status masih pending
        if ($task->status === 'pending') {
            $task->update([
                'status' => 'in_progress',
                'started_at' => now()
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Picking task started successfully'
        ]);
    }

    /**
     * POST: Submit Picking (Selesai per Item atau per Task)
     * Mobile mengirim data hasil scan aktual.
     */
    public function submit(Request $request, $id)
    {
        $task = PickingList::findOrFail($id);

        if ($task->status === 'completed') {
             return response()->json(['message' => 'Task already completed'], 200);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:picking_list_items,id',
            'items.*.qty_picked' => 'required|numeric|min:0',
            'items.*.uom' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        DB::beginTransaction();
        try {
            $userId = Auth::id();
            $warehouseId = $task->warehouse_id;

            // 1. Destination (Staging)
            $stagingZone = Zone::where('code', 'STG')->first();
            if (!$stagingZone) throw new \Exception("Zone STG not found");

            $destLocation = Location::where('locatable_id', $warehouseId)
                ->where('locatable_type', Warehouse::class)
                ->where('zone_id', $stagingZone->id)
                ->where('ownership_type', 'owned')
                ->where('is_sellable', false)
                ->where('status', true)
                ->first();

            if (!$destLocation) throw new \Exception("Staging Location (Owned) not found in Warehouse ID $warehouseId");

            // 2. Loop Items
            foreach ($request->items as $input) {
                $plItem = PickingListItem::with('sources.inventory')->find($input['item_id']);
                $product = Product::with('uoms')->find($plItem->product_id);

                $qtyInput = (float) $input['qty_picked'];

                // --- LOGIKA KONVERSI UOM ---
                $inputUom = $input['uom'] ?? $plItem->uom ?? $product->base_uom;
                $inputUomClean = strtoupper(trim($inputUom));
                $baseUomClean = strtoupper(trim($product->base_uom));

                $conversionRate = 1;
                $uomData = $product->uoms->first(function($u) use ($inputUomClean) {
                    return strtoupper(trim($u->uom_name)) === $inputUomClean;
                });

                if ($uomData) {
                    $conversionRate = $uomData->conversion_rate;
                } elseif ($inputUomClean !== $baseUomClean) {
                    Log::warning("Picking UoM Unknown: $inputUomClean for product {$product->name}. Assuming rate 1.");
                }

                // Konversi ke Base UoM (Total PCS)
                // Ini variabel kunci yang harus dipakai untuk update DB & Move Stock
                $qtyMovedBase = round($qtyInput * $conversionRate, 4);

                // --- UPDATE PICKED QUANTITY (ATOMIC) ---
                if ($qtyMovedBase > 0) {
                    $currentPicked = (float) ($plItem->quantity_picked ?? 0);
                    // [FIXED] Use $qtyMovedBase (Converted) instead of raw input
                    $plItem->update(['quantity_picked' => $currentPicked + $qtyMovedBase]);
                }

                if ($qtyMovedBase <= 0) continue;

                // 3. Move Stock (Use Converted Quantity)
                $remainingToMove = $qtyMovedBase;

                foreach ($plItem->sources as $source) {
                    if ($remainingToMove <= 0.00001) break;

                    $sourceInventory = $source->inventory;

                    if ($sourceInventory->avail_stock < 0.00001) continue;

                    $allocatedHere = (float) $source->quantity_to_pick_from_source;
                    $qtyToTake = min($remainingToMove, $allocatedHere, (float)$sourceInventory->avail_stock);

                    // A. Decrease Source
                    $sourceInventory->decrement('avail_stock', $qtyToTake);

                    InventoryMovement::create([
                        'inventory_id' => $sourceInventory->id,
                        'quantity_change' => -$qtyToTake,
                        'stock_after_move' => $sourceInventory->avail_stock,
                        'type' => 'PICKING_OUT',
                        'reference_type' => PickingList::class,
                        'reference_id' => $task->id,
                        'user_id' => $userId,
                        'notes' => "Picked {$qtyToTake} {$product->base_uom} to Staging"
                    ]);

                    // B. Increase Staging
                    $stagingInv = Inventory::firstOrCreate(
                        [
                            'location_id' => $destLocation->id,
                            'product_id' => $sourceInventory->product_id,
                            'batch' => $sourceInventory->batch,
                        ],
                        [
                            'business_id' => $task->business_id,
                            'sled' => $sourceInventory->sled,
                            'avail_stock' => 0
                        ]
                    );

                    $stagingInv->increment('avail_stock', $qtyToTake);

                    InventoryMovement::create([
                        'inventory_id' => $stagingInv->id,
                        'quantity_change' => $qtyToTake,
                        'stock_after_move' => $stagingInv->avail_stock,
                        'type' => 'STAGING_IN',
                        'reference_type' => PickingList::class,
                        'reference_id' => $task->id,
                        'user_id' => $userId,
                        'notes' => "Received from Picking"
                    ]);

                    $remainingToMove -= $qtyToTake;
                }
            }

            // 4. Update Status (Hanya In Progress)
            if ($task->status === 'pending') {
                $task->update([
                    'status' => 'in_progress',
                    'started_at' => now()
                ]);
            }

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Picking progress saved.']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Picking Submit Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST: Finish Picking (Explicit Finish)
     */
    public function finish($id)
    {
        $task = PickingList::findOrFail($id);

        $task->load('items');

        $isFullyPicked = $task->items->every(function ($item) {
            $picked = (float) ($item->quantity_picked ?? 0);
            $target = (float) $item->total_quantity_to_pick;
            // Tolerance for float
            return $picked >= ($target - 0.001);
        });

        if (!$isFullyPicked) {
            $task->items->each(function($item) {
                $picked = (float)($item->quantity_picked ?? 0);
                $target = (float)$item->total_quantity_to_pick;
                if ($picked < ($target - 0.001)) {
                    Log::warning("Picking Incomplete Item ID {$item->id}: Picked $picked / Target $target");
                }
            });

            return response()->json(['message' => 'Picking not fully completed'], 400);
        }

        $task->update([
            'status' => 'completed',
            'completed_at' => now()
        ]);

        if ($task->sourceable) {
            $task->sourceable->update(['status' => 'ready_to_ship']);
        }

        return response()->json(['status' => 'success', 'message' => 'Picking finished']);
    }
}
