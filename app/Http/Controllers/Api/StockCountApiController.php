<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockCountItemResource;
use App\Http\Resources\StockCountTaskResource;
use App\Models\Outlet;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StockCountApiController extends Controller
{
    /**
     * GET: List Stock Count Tasks (Active Only)
     * Menampilkan tugas opname yang statusnya 'in_progress' untuk Outlet user saat ini.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Tentukan Outlet User
        // Asumsi user terikat ke outlet via locationable
        $outletId = null;
        if ($user->locationable_type === 'App\Models\Outlet') {
            $outletId = $user->locationable_id;
        }

        if (!$outletId) {
            // Jika user tidak terikat outlet, kembalikan kosong atau error
            return response()->json(['data' => []]);
        }

        $tasks = StockCount::query()
            ->where('business_id', $user->business_id)
            ->where('countable_type', Outlet::class)
            ->where('countable_id', $outletId)
            ->where('status', 'in_progress') // Hanya tampilkan yang sedang berjalan
            ->withCount('items') // Untuk progress bar
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => StockCountTaskResource::collection($tasks)
        ]);
    }

       /**
     * GET: Detail Item Stock Count
     */
    public function show($id)
    {
        // Load relasi yang dibutuhkan
        $task = StockCount::with([
                'items.product',
                'items.inventory.location'
            ])
            ->where('id', $id)
            ->firstOrFail();

        // Validasi Akses (Pastikan user di outlet yang sama atau Owner)
        $user = Auth::user();
        $userOutletId = ($user->locationable_type === 'App\Models\Outlet') ? $user->locationable_id : null;

        if ($userOutletId && $task->countable_id != $userOutletId && !$user->hasRole('Owner')) {
             return response()->json(['message' => 'Unauthorized access to this stock count.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'task' => new StockCountTaskResource($task),
                // Ini akan memanggil toArray() dari StockCountItemResource yang sudah kita update
                'items' => StockCountItemResource::collection($task->items),
            ]
        ]);
    }

    // /**
    //  * GET: Detail Item Stock Count
    //  * Menampilkan daftar barang yang harus dihitung dalam tugas ini.
    //  */
    // public function show($id)
    // {
    //     $task = StockCount::with(['items.product', 'items.inventory.location'])
    //         ->where('id', $id)
    //         ->firstOrFail();

    //     // Validasi Akses (Pastikan user di outlet yang sama)
    //     $user = Auth::user();
    //     if ($task->countable_id != $user->locationable_id && !$user->hasRole('Owner')) {
    //          return response()->json(['message' => 'Unauthorized access to this stock count.'], 403);
    //     }

    //     return response()->json([
    //         'status' => 'success',
    //         'data' => [
    //             'task' => new StockCountTaskResource($task),
    //             'items' => StockCountItemResource::collection($task->items),
    //         ]
    //     ]);
    // }

    /**
     * POST: Update Count Item
     * User scan barang & input qty.
     */
    public function updateItem(Request $request, $id)
    {
        // $id adalah ID dari StockCountItem
        $item = StockCountItem::findOrFail($id);

        // Validasi status Header harus in_progress
        if ($item->stockCount->status !== 'in_progress') {
            return response()->json(['message' => 'Stock count is not in progress.'], 400);
        }

        $validator = Validator::make($request->all(), [
            'qty' => 'required|numeric|min:0',
            'is_zero_count' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        // Update Hasil Hitungan
        // Sesuai logika 'Outlet' di Filament Resource Anda: Update langsung final_counted_stock
        $item->update([
            'final_counted_stock' => $request->qty,
            'is_zero_count' => $request->boolean('is_zero_count'),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Item updated',
            'data' => new StockCountItemResource($item)
        ]);
    }

    /**
     * POST: Submit / Finish Stock Count
     * Mengubah status menjadi pending_approval (Selesai hitung di lapangan)
     */
    public function submit($id)
    {
        $task = StockCount::findOrFail($id);

        if ($task->status !== 'in_progress') {
            return response()->json(['message' => 'Task is not active.'], 400);
        }

        // Validasi: Apakah semua item sudah dihitung?
        // (Opsional: Bisa di-skip jika kebijakan membolehkan partial count)
        $uncounted = $task->items()->whereNull('final_counted_stock')->count();

        if ($uncounted > 0) {
             return response()->json([
                 'message' => "Masih ada $uncounted item yang belum dihitung. Set '0' jika kosong.",
                 'uncounted_count' => $uncounted
             ], 422);
        }

        DB::transaction(function () use ($task) {
            $task->update([
                'status' => 'pending_approval',
                'completed_at' => now()
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Stock count submitted for approval.'
        ]);
    }
}
