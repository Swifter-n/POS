<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PutawayItemResource;
use App\Http\Resources\PutawayTaskResource;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\PutAwayEntry;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PutawayApiController extends Controller
{

 /**
     * GET: Master Locations (Untuk Sync Offline)
     * Mengambil lokasi aktif milik Business User via Warehouse/Outlet
     */
    public function getMasterLocations(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        // PERBAIKAN QUERY:
        // Gunakan whereHasMorph untuk mengecek business_id milik Parent (Warehouse/Outlet)
        $locations = Location::query()
            ->where('status', true)
            ->whereHasMorph(
                'locatable',
                [\App\Models\Warehouse::class, \App\Models\Outlet::class],
                function ($query) use ($businessId) {
                    $query->where('business_id', $businessId);
                }
            )
            // Opsional: Filter hanya tipe penyimpanan (Bin/Rack) agar payload tidak berat
            ->whereIn('type', ['BIN', 'RACK', 'PALLET', 'AREA'])
            ->select(['id', 'code', 'name', 'barcode'])
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $locations
        ]);
    }

    /**
     * GET: List Tugas Saya
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = StockTransfer::query()
            ->where('business_id', $user->business_id)
            ->where('transfer_type', 'put_away')
            ->whereIn('status', ['pending_pick', 'in_progress']) // Hanya yang aktif
            ->with(['sourceLocation']);

        // Filter: Hanya tugas milik user ini (kecuali Manager/Owner)
        if (!$user->hasRole('Owner') && !$user->hasRole('Manager Gudang')) {
            $query->where('assigned_user_id', $user->id);
        }

        $tasks = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => PutawayTaskResource::collection($tasks)
        ]);
    }

    /**
     * GET: Detail Tugas & Items
     */
    public function show($id)
    {
        $task = StockTransfer::with(['items.product', 'items.suggestedLocation.zone', 'items.putAwayEntries'])
            ->where('id', $id)
            ->firstOrFail();

        // Validasi Akses
        if ($task->business_id !== Auth::user()->business_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'task' => new PutawayTaskResource($task),
                'items' => PutawayItemResource::collection($task->items),
            ]
        ]);
    }

    /**
     * POST: Mulai Tugas (Start)
     */
    public function start($id)
    {
        $task = StockTransfer::findOrFail($id);

        if ($task->status === 'draft') {
            return response()->json(['message' => 'Task is still draft. Ask manager to assign it.'], 400);
        }

        if ($task->status === 'pending_pick') {
            $task->update([
                'status' => 'in_progress',
                'started_at' => now()
            ]);
        }

        return response()->json(['status' => 'success', 'message' => 'Task started']);
    }

    /**
     * POST: Log Entry (Sync Point dari Mobile)
     * Menerima data scan per item dari Flutter
     */
    public function logEntry(Request $request, $id)
    {
        $task = StockTransfer::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity_moved' => 'required|numeric|min:0.01',
            'destination_location_id' => 'required|exists:locations,id',
            'batch' => 'nullable|string',
            'force_override' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        // Validasi Anti-Loop
        if ($task->source_location_id == $request->destination_location_id) {
            return response()->json(['message' => 'Lokasi Tujuan tidak boleh sama dengan Lokasi Asal (RCV).'], 422);
        }

        $taskItem = StockTransferItem::where('stock_transfer_id', $task->id)
            ->where('product_id', $request->product_id)
            ->first();

        if (!$taskItem) {
            return response()->json(['message' => 'Product not found in this task'], 404);
        }

        // --- VALIDASI LOKASI (Suggestion Check) ---
        if ($taskItem->suggested_location_id) {
            if ($taskItem->suggested_location_id != $request->destination_location_id) {
                if (!$request->boolean('force_override')) {
                    $suggestedName = Location::find($taskItem->suggested_location_id)?->name;
                    return response()->json([
                        'message' => "Lokasi tidak sesuai saran sistem ($suggestedName). Aktifkan override untuk melanjutkan.",
                        'code' => 'LOCATION_MISMATCH'
                    ], 422);
                }
            }
        }

        // Cek Over Delivery
        $totalMoved = $taskItem->putAwayEntries()->sum('quantity_moved');
        $remaining = $taskItem->quantity - $totalMoved;
        if ($request->quantity_moved > ($remaining + 0.001)) {
            return response()->json(['message' => "Quantity exceeds remaining task ($remaining)."], 422);
        }

        // ============================================================
        // [FIX UTAMA] LOGIKA PEWARISAN BATCH (BATCH INHERITANCE)
        // ============================================================

        // 1. Default: Ambil batch dari request (Scan Manual User)
        $finalBatch = $request->batch;

        // 2. Fallback: Jika user tidak scan batch (null/empty),
        //    TAPI di data Task Item (STO) sudah ada batch-nya,
        //    Maka GUNAKAN batch dari Task Item tersebut.
        if (empty($finalBatch) && !empty($taskItem->batch)) {
            $finalBatch = $taskItem->batch;
            Log::info("LogEntry: Batch inherited from TaskItem. Batch: $finalBatch");
        }

        // ============================================================

        PutAwayEntry::create([
            'stock_transfer_id' => $task->id,
            'stock_transfer_item_id' => $taskItem->id,
            'product_id' => $request->product_id,
            'destination_location_id' => $request->destination_location_id,
            'quantity_moved' => $request->quantity_moved,
            'user_id' => Auth::id(),

            // Simpan Batch Final ke Database
            'batch' => $finalBatch
        ]);

        return response()->json(['status' => 'success', 'message' => 'Entry logged']);
    }

    /**
     * POST: Execute / Finish (Finalisasi Stok)
     * V5: Fix Foreign Key Error (Update to 0 instead of Delete)
     */
    public function execute($id)
    {
        $task = StockTransfer::with(['putAwayEntries.stockTransferItem', 'sourceLocation'])->findOrFail($id);

        if ($task->status === 'completed') {
             return response()->json(['message' => 'Task already completed'], 200);
        }

        if ($task->putAwayEntries->isEmpty()) {
            return response()->json(['message' => 'No entries logged. Cannot finish.'], 400);
        }

        DB::beginTransaction();
        try {
            $userId = Auth::id();
            $sourceLocId = $task->source_location_id;

            Log::info("START EXECUTE PUTAWAY Task #{$id}");

            foreach ($task->putAwayEntries as $entry) {
                // 1. Hitung Qty dalam Base UoM
                $qtyInput = (float) $entry->quantity_moved;
                if ($qtyInput <= 0) continue;

                $product = Product::with('uoms')->find($entry->product_id);

                // Normalisasi UoM
                $inputUomClean = strtoupper(trim($entry->uom));

                // Fallback Logic UoM (Sesuai perbaikan sebelumnya)
                if (empty($inputUomClean) && $entry->stockTransferItem) {
                    $inputUomClean = strtoupper(trim($entry->stockTransferItem->uom));
                }
                if (empty($inputUomClean)) {
                    $inputUomClean = strtoupper(trim($product->base_uom));
                }

                $baseUomClean = strtoupper(trim($product->base_uom));

                // Hitung Rate
                $uomData = $product->uoms->first(function($item) use ($inputUomClean) {
                    return strtoupper(trim($item->uom_name)) === $inputUomClean;
                });

                $conversionRate = 1;
                if ($uomData) {
                    $conversionRate = $uomData->conversion_rate;
                } elseif ($inputUomClean === $baseUomClean) {
                    $conversionRate = 1;
                }

                $qtyMovedBase = round($qtyInput * $conversionRate, 4);

                if ($sourceLocId == $entry->destination_location_id) continue;

                // 2. AMBIL STOK SUMBER (RCV)
                $sourceQuery = Inventory::where('location_id', $sourceLocId)
                    ->where('product_id', $entry->product_id)
                    ->where('avail_stock', '>', 0.00001);

                // Batch Resolver
                $targetBatch = $entry->batch;
                if (empty($targetBatch) && !empty($entry->stockTransferItem->batch)) {
                    $targetBatch = $entry->stockTransferItem->batch;
                }

                if (!empty($targetBatch)) {
                   $sourceQuery->where('batch', $targetBatch);
                } else {
                   $sourceQuery->where(function($q) {
                       $q->whereNull('batch')->orWhere('batch', '');
                   });
                }

                $sourceInventories = $sourceQuery->orderBy('sled', 'asc')
                                                 ->orderBy('id', 'asc')
                                                 ->lockForUpdate()
                                                 ->get();

                $totalSourceAvailable = $sourceInventories->sum('avail_stock');

                if ($totalSourceAvailable < ($qtyMovedBase - 0.001)) {
                     $batchInfo = empty($targetBatch) ? "NO BATCH" : "Batch: {$targetBatch}";
                     throw new \Exception("Stok Fisik Kurang di RCV! Butuh: $qtyMovedBase, Ada: $totalSourceAvailable. ($batchInfo)");
                }

                $remainingToMoveBase = $qtyMovedBase;

                foreach ($sourceInventories as $inv) {
                    if ($remainingToMoveBase <= 0.00001) break;

                    $currentStock = (float) $inv->avail_stock;
                    $qtyBatchBase = min($remainingToMoveBase, $currentStock);
                    $qtyBatchBase = round($qtyBatchBase, 4);

                    // --- A. KURANGI SUMBER ---
                    $newSourceStock = round($currentStock - $qtyBatchBase, 4);

                    // [PERBAIKAN UTAMA]
                    // Jangan DELETE, tapi UPDATE ke 0 (atau nilai sisa).
                    // Ini menjaga Foreign Key tetap valid untuk log movement.
                    $inv->avail_stock = $newSourceStock;
                    $inv->save();

                    Log::info("Source Inv ID {$inv->id} Updated to: {$newSourceStock}");

                    // Catat Log Keluar (Foreign Key inventory_id ke ID 109 Aman karena row tidak dihapus)
                    InventoryMovement::create([
                        'inventory_id' => $inv->id,
                        'quantity_change' => -1 * $qtyBatchBase,
                        'stock_after_move' => $newSourceStock,
                        'type' => 'PUTAWAY_OUT',
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $task->id,
                        'user_id' => $userId,
                        'notes' => "Moved {$qtyBatchBase} to Loc #{$entry->destination_location_id}"
                    ]);

                    // --- B. UPDATE TUJUAN ---
                    $destQuery = Inventory::where('location_id', $entry->destination_location_id)
                        ->where('product_id', $inv->product_id);

                    if (!empty($inv->batch)) {
                        $destQuery->where('batch', $inv->batch);
                    } else {
                        $destQuery->where(function($q) {
                            $q->whereNull('batch')->orWhere('batch', '');
                        });
                    }

                    $destInv = $destQuery->first();

                    if ($destInv) {
                        $newDestStock = round((float)$destInv->avail_stock + $qtyBatchBase, 4);
                        $destInv->avail_stock = $newDestStock;
                        $destInv->save();
                    } else {
                        $newDestStock = $qtyBatchBase;
                        $destInv = Inventory::create([
                            'location_id' => $entry->destination_location_id,
                            'product_id' => $inv->product_id,
                            'batch' => $inv->batch,
                            'business_id' => $task->business_id,
                            'sled' => $inv->sled,
                            'avail_stock' => $newDestStock
                        ]);
                    }

                    InventoryMovement::create([
                        'inventory_id' => $destInv->id,
                        'quantity_change' => $qtyBatchBase,
                        'stock_after_move' => $newDestStock,
                        'type' => 'PUTAWAY_IN',
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $task->id,
                        'user_id' => $userId,
                        'notes' => "Received from Loc #{$sourceLocId}"
                    ]);

                    $remainingToMoveBase -= $qtyBatchBase;
                }

                // Update Kapasitas Bin
                $destLocation = Location::find($entry->destination_location_id);
                if ($destLocation) {
                    $destLocation->increment('current_pallets', 1);
                }
            }

            $task->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Putaway completed. Stock moved successfully.']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Putaway Execute Error Task #{$id}: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

}
    // public function execute($id)
    // {
    //     // Load task beserta relasinya
    //     $task = StockTransfer::with(['putAwayEntries', 'sourceLocation'])->findOrFail($id);

    //     // 1. Validasi Status Task
    //     if ($task->status === 'completed') {
    //          return response()->json(['message' => 'Task already completed'], 200);
    //     }

    //     if ($task->putAwayEntries->isEmpty()) {
    //         return response()->json(['message' => 'No entries logged. Cannot finish.'], 400);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         $userId = Auth::id();
    //         $sourceLocId = $task->source_location_id;

    //         Log::info("START EXECUTE PUTAWAY Task #{$id} from Location ID: {$sourceLocId}");

    //         // Loop setiap entry scan user
    //         foreach ($task->putAwayEntries as $entry) {
    //             // Pastikan casting ke float bersih
    //             $qtyMoved = (float) $entry->quantity_moved;

    //             if ($qtyMoved <= 0) continue;

    //             // 2. VALIDASI ANTI-LOOP (Source != Dest)
    //             if ($sourceLocId == $entry->destination_location_id) {
    //                 Log::warning("Skipping circular move for Item {$entry->product_id}");
    //                 continue; // Skip jika lokasi sama
    //             }

    //             // 3. CARI STOK SUMBER (RCV)
    //             $sourceQuery = Inventory::where('location_id', $sourceLocId)
    //                 ->where('product_id', $entry->product_id)
    //                 ->where('avail_stock', '>', 0);

    //             // Filter Batch Spesifik (Jika ada di entry STO)
    //             if (!empty($entry->batch)) {
    //                $sourceQuery->where('batch', $entry->batch);
    //             }

    //             // Gunakan lockForUpdate untuk konsistensi data & urutkan FEFO
    //             $sourceInventories = $sourceQuery->orderBy('sled', 'asc')
    //                                              ->lockForUpdate()
    //                                              ->get();

    //             // Validasi Total Ketersediaan
    //             $totalSourceAvailable = $sourceInventories->sum('avail_stock');
    //             if ($totalSourceAvailable < ($qtyMoved - 0.001)) {
    //                  throw new \Exception("Stok Fisik Kurang di RCV! Product ID: {$entry->product_id}. Butuh: $qtyMoved, Ada: $totalSourceAvailable");
    //             }

    //             $remainingToMove = $qtyMoved;

    //             // 4. PROSES PEMINDAHAN (FIFO Loop)
    //             foreach ($sourceInventories as $inv) {
    //                 if ($remainingToMove <= 0.00001) break;

    //                 // Hitung berapa yang bisa diambil dari batch ini
    //                 $currentStock = (float) $inv->avail_stock;
    //                 $qtyBatch = min($remainingToMove, $currentStock);

    //                 // --- A. UPDATE SUMBER (RCV) ---
    //                 $newSourceStock = $currentStock - $qtyBatch;
    //                 $inv->avail_stock = $newSourceStock;
    //                 $inv->save(); // Explicit Save

    //                 // PENTING: HAPUS ROW JIKA KOSONG (Agar tidak nyangkut di RCV)
    //                 if ($newSourceStock <= 0.00001) {
    //                     $inv->delete();
    //                     Log::info("Source Inventory ID {$inv->id} deleted (Empty).");
    //                 }

    //                 // Log Pergerakan Keluar
    //                 InventoryMovement::create([
    //                     'inventory_id' => $inv->id,
    //                     'quantity_change' => -1 * $qtyBatch,
    //                     'stock_after_move' => $newSourceStock,
    //                     'type' => 'PUTAWAY_OUT',
    //                     'reference_type' => StockTransfer::class,
    //                     'reference_id' => $task->id,
    //                     'user_id' => $userId,
    //                     'notes' => "Moved Out to Loc #{$entry->destination_location_id}"
    //                 ]);

    //                 // --- B. UPDATE TUJUAN (BIN/PALLET) ---
    //                 // Cari stok eksisting di tujuan dengan batch yang sama
    //                 $destInv = Inventory::where('location_id', $entry->destination_location_id)
    //                     ->where('product_id', $inv->product_id)
    //                     ->where('batch', $inv->batch)
    //                     ->first();

    //                 if ($destInv) {
    //                     // Jika ada, tambah stok
    //                     $destInv->avail_stock += $qtyBatch;
    //                     $destInv->save();
    //                 } else {
    //                     // Jika tidak ada, buat baru
    //                     $destInv = Inventory::create([
    //                         'location_id' => $entry->destination_location_id,
    //                         'product_id' => $inv->product_id,
    //                         'batch' => $inv->batch,
    //                         'business_id' => $task->business_id,
    //                         'sled' => $inv->sled,
    //                         'avail_stock' => $qtyBatch
    //                     ]);
    //                 }

    //                 // Log Pergerakan Masuk
    //                 InventoryMovement::create([
    //                     'inventory_id' => $destInv->id,
    //                     'quantity_change' => $qtyBatch,
    //                     'stock_after_move' => $destInv->avail_stock,
    //                     'type' => 'PUTAWAY_IN',
    //                     'reference_type' => StockTransfer::class,
    //                     'reference_id' => $task->id,
    //                     'user_id' => $userId,
    //                     'notes' => "Moved In from Loc #{$sourceLocId}"
    //                 ]);

    //                 $remainingToMove -= $qtyBatch;
    //             }

    //             // 5. UPDATE KAPASITAS BIN
    //             $destLocation = Location::find($entry->destination_location_id);
    //             if ($destLocation) {
    //                 $destLocation->increment('current_pallets', 1);
    //             }
    //         }

    //         // 6. FINALISASI STATUS TASK
    //         $task->update([
    //             'status' => 'completed',
    //             'completed_at' => now()
    //         ]);

    //         DB::commit();

    //         Log::info("Putaway Task #{$id} COMPLETED Successfully. Stock Moved.");

    //         return response()->json(['status' => 'success', 'message' => 'Putaway completed. Stock moved successfully.']);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error("Putaway Execute Error Task #{$id}: " . $e->getMessage());
    //         return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    //     }
    // }
    // public function execute($id)
    // {
    //     $task = StockTransfer::with(['putAwayEntries', 'sourceLocation'])->findOrFail($id);

    //     // Cek status agar tidak double posting
    //     if ($task->status === 'completed') {
    //          return response()->json(['message' => 'Task already completed'], 200);
    //     }

    //     if ($task->putAwayEntries->isEmpty()) {
    //         return response()->json(['message' => 'No entries logged. Cannot finish.'], 400);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         $userId = Auth::id();
    //         // Lokasi Asal (RCV) diambil dari Header Task
    //         $sourceLocId = $task->source_location_id;

    //         // Loop semua entry (hasil scan user)
    //         foreach ($task->putAwayEntries as $entry) {
    //             $qtyMoved = round((float) $entry->quantity_moved, 4);
    //             if ($qtyMoved <= 0) continue;

    //             // 1. IDENTIFIKASI STOK DI SUMBER (RCV)
    //             $sourceQuery = Inventory::where('location_id', $sourceLocId)
    //                 ->where('product_id', $entry->product_id)
    //                 ->where('avail_stock', '>', 0);

    //             // [PENTING] Jika Entry punya Batch spesifik (dari STO), harus match persis.
    //             // Jika null (dari PO), ambil yang paling tua (FEFO).
    //             if (!empty($entry->batch)) {
    //                $sourceQuery->where('batch', $entry->batch);
    //             }

    //             // Urutkan FEFO (First Expired First Out)
    //             $sourceInventories = $sourceQuery->orderBy('sled', 'asc')->get();

    //             // Validasi Ketersediaan Stok
    //             $totalSourceStock = $sourceInventories->sum('avail_stock');
    //             // Toleransi float 0.001
    //             if ($totalSourceStock < ($qtyMoved - 0.001)) {
    //                  throw new \Exception("Stok Fisik Kurang di Receiving Area! Product: {$entry->product_id}. Butuh: $qtyMoved, Ada: $totalSourceStock");
    //             }

    //             $remainingToMove = $qtyMoved;

    //             foreach ($sourceInventories as $inv) {
    //                 if ($remainingToMove <= 0.00001) break;

    //                 // Ambil sebanyak mungkin dari batch ini
    //                 //$qtyBatch = min($remainingToMove, $inv->avail_stock);
    //                 $currentStock = round((float) $inv->avail_stock, 4);
    //                 $qtyBatch = min($remainingToMove, $currentStock);
    //                 $qtyBatch = round($qtyBatch, 4);

    //                 $stockAfterSource = round($currentStock - $qtyBatch, 4);

    //                 // A. KURANGI SUMBER (DEBIT)
    //                 $inv->decrement('avail_stock', $qtyBatch);

    //                 // [FITUR BARU] Hapus record RCV jika stok habis (agar tabel bersih)
    //                 if ($inv->refresh()->avail_stock <= 0.00001) {
    //                     $inv->delete();
    //                 }

    //                 InventoryMovement::create([
    //                     'inventory_id' => $inv->id,
    //                     'quantity_change' => -1 * $qtyBatch, // Pastikan negatif
    //                     'stock_after_move' => $stockAfterSource, // $3 (Parameter ke-3 yang error)
    //                     'type' => 'PUTAWAY_OUT',
    //                     'reference_type' => StockTransfer::class,
    //                     'reference_id' => $task->id,
    //                     'user_id' => $userId,
    //                     'notes' => 'Moved Out from RCV'
    //                 ]);

    //                 // B. TAMBAH TUJUAN (CREDIT)
    //                 // Pastikan destinasi menggunakan ID dari SCAN USER ($entry->destination_location_id)
    //                 $destInv = Inventory::firstOrCreate(
    //                     [
    //                         'location_id' => $entry->destination_location_id, // <--- KUNCI PERPINDAHAN
    //                         'product_id' => $inv->product_id,
    //                         'batch' => $inv->batch
    //                     ],
    //                     [
    //                         'business_id' => $task->business_id,
    //                         'sled' => $inv->sled,
    //                         'avail_stock' => 0
    //                     ]
    //                 );

    //                 $destCurrentStock = round((float) $destInv->avail_stock, 4);
    //                 $destInv->increment('avail_stock', $qtyBatch);

    //                 // Hitung Stock After Move Destination
    //                 $stockAfterDest = round($destCurrentStock + $qtyBatch, 4);

    //                 //$destInv->increment('avail_stock', $qtyBatch);

    //                 InventoryMovement::create([
    //                     'inventory_id' => $destInv->id,
    //                     'quantity_change' => $qtyBatch,
    //                     'stock_after_move' => $stockAfterDest,
    //                     'type' => 'PUTAWAY_IN',
    //                     'reference_type' => StockTransfer::class,
    //                     'reference_id' => $task->id,
    //                     'user_id' => $userId,
    //                     'notes' => 'Moved In to Bin'
    //                 ]);

    //                 $remainingToMove -= $qtyBatch;
    //             }

    //             // 2. UPDATE KAPASITAS BIN (Pallet Count)
    //             $destLocation = Location::find($entry->destination_location_id);
    //             if ($destLocation) {
    //                 // Increment kapasitas terpakai
    //                 $destLocation->increment('current_pallets', 1);
    //             }
    //         }

    //         // Finalisasi Status Task
    //         $task->update([
    //             'status' => 'completed',
    //             'completed_at' => now()
    //         ]);

    //         DB::commit();
    //         return response()->json(['status' => 'success', 'message' => 'Putaway completed. Stock moved successfully.']);

    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error("Putaway Execute Error: " . $e->getMessage());
    //         return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
    //     }
    // }

//}
