<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WmsStockCountItemResource;
use App\Models\StockCount;
use App\Models\StockCountEntry;
use App\Models\StockCountItem;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WmsStockCountApiController extends Controller
{

    public function index(Request $request)
    {
        $user = $request->user();

        $query = StockCount::query()
            ->where('business_id', $user->business_id)
            ->where('countable_type', Warehouse::class); // Wajib Warehouse

        // Cek Role: Owner/Manager lihat semua
        if ($user->hasRole('Owner') || $user->hasRole('Manager Gudang')) {
            $query->whereIn('status', ['in_progress', 'pending_validation']);
        } else {
            // Staff Biasa: Cek assignment di JSON
            $query->where(function($q) use ($user) {
                $idStr = (string) $user->id;
                // Kadang filament simpan int, kadang string. Kita cast user id ke string.
                // Note: Pastikan database column JSON kompatibel.

                // SKENARIO 1: Counter (Tim Kuning/Hijau)
                // Hanya bisa lihat saat 'in_progress'
                $q->where(function($sub) use ($idStr) {
                    $sub->where('status', 'in_progress')
                        ->where(function($teamQ) use ($idStr) {
                            $teamQ->whereJsonContains('assigned_teams->yellow', $idStr)
                                  ->orWhereJsonContains('assigned_teams->green', $idStr);
                        });
                });

                // SKENARIO 2: Validator (Tim Putih)
                // Bisa lihat saat 'in_progress' DAN 'pending_validation'
                $q->orWhere(function($sub) use ($idStr) {
                    $sub->whereIn('status', ['in_progress', 'pending_validation'])
                        ->whereJsonContains('assigned_teams->white', $idStr);
                });
            });
        }

        $tasks = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $tasks->map(function($task) use ($user) {
                return [
                    'id' => $task->id,
                    'count_number' => $task->count_number,
                    'location' => $task->countable->name ?? 'Unknown',
                    'date' => $task->created_at->format('Y-m-d'),
                    'status' => $task->status, // Penting untuk UI Mobile
                    'my_team' => $this->getUserTeam($task, $user->id),
                ];
            })
        ]);
    }

    /**
     * GET: Detail Item (Blind / Validator View)
     */
    public function show($id)
    {
        $task = StockCount::with(['items.product', 'items.entries', 'items.inventory.location'])
            ->where('countable_type', Warehouse::class)
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'task_id' => $task->id,
                'count_number' => $task->count_number,
                'status' => $task->status,
                'my_team' => $this->getUserTeam($task, Auth::id()),
                // Resource akan otomatis handle visibility field berdasarkan role
                'items' => WmsStockCountItemResource::collection($task->items),
            ]
        ]);
    }

    /**
     * POST: Submit Entry (Counter Input)
     */
    public function submitEntry(Request $request, $id)
    {
        $task = StockCount::findOrFail($id);

        if ($task->status !== 'in_progress') {
            return response()->json(['message' => 'Task not active. Status: ' . $task->status], 400);
        }

        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:stock_count_items,id',
            'qty' => 'required|numeric|min:0',
            'is_zero' => 'boolean',
            'uom' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $userId = Auth::id();
        $team = $this->getUserTeam($task, $userId);

        // Tolak jika user adalah Validator (White) tapi mencoba submit entry biasa
        // Validator seharusnya pakai endpoint /validate
        if ($team === 'White' || !$team) {
             // Opsional: Bolehkan White input jika merangkap tugas?
             // Untuk strict flow:
             // return response()->json(['message' => 'Validators should use Validate function, or you are not assigned.'], 403);

             // Fallback aman: Jika team tidak terdeteksi (misal Manager tanpa assignment)
             if (!$team && (Auth::user()->hasRole('Owner') || Auth::user()->hasRole('Manager Gudang'))) {
                 $team = 'Manager'; // Flag khusus
             } else if (!$team) {
                 return response()->json(['message' => 'You are not assigned to this task.'], 403);
             }
        }

        StockCountEntry::updateOrCreate(
            [
                'stock_count_item_id' => $request->item_id,
                'team_name' => $team,
            ],
            [
                'user_id' => $userId,
                'counted_quantity' => $request->boolean('is_zero') ? 0 : $request->qty,
                'is_zero_count' => $request->boolean('is_zero'),
                'counted_uom' => $request->uom ?? 'PCS',
            ]
        );

        return response()->json(['status' => 'success', 'message' => 'Count saved']);
    }
   
    // Helper: Tentukan user ini masuk tim mana
    private function getUserTeam($task, $userId)
    {
        $teams = $task->assigned_teams ?? [];
        $userIdStr = (string) $userId;
        $userIdInt = (int) $userId;

        // Cek array (kadang disimpan sbg string, kadang int di JSON)
        if (in_array($userIdStr, $teams['yellow'] ?? []) || in_array($userIdInt, $teams['yellow'] ?? [])) {
            return 'Kuning';
        }
        if (in_array($userIdStr, $teams['green'] ?? []) || in_array($userIdInt, $teams['green'] ?? [])) {
            return 'Hijau';
        }
        return null;
    }

        /**
     * POST: Validate Item (Set Final Count)
     * Endpoint khusus untuk Validator/Manager menetapkan angka final via Mobile.
     */
    public function validateItem(Request $request, $id)
    {
        $task = StockCount::findOrFail($id);

        // Cek Status (Harus dalam fase validasi atau progress)
        if (!in_array($task->status, ['in_progress', 'pending_validation'])) {
            return response()->json(['message' => 'Task is not open for validation.'], 400);
        }

        // Cek Hak Akses (Wajib Tim Putih/Manager)
        $user = Auth::user();
        $isValidator = $user->hasRole('Owner') ||
                       $user->hasRole('Manager Gudang') ||
                       in_array($user->id, $task->assigned_teams['white'] ?? []);

        if (!$isValidator) {
            return response()->json(['message' => 'Unauthorized. Only Validators can set final count.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'item_id' => 'required|exists:stock_count_items,id',
            'final_qty' => 'required|numeric|min:0',
            'is_zero' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $item = StockCountItem::where('stock_count_id', $task->id)
            ->where('id', $request->item_id)
            ->firstOrFail();

        // UPDATE FINAL COUNT
        $item->update([
            'final_counted_stock' => $request->boolean('is_zero') ? 0 : $request->final_qty,
            'final_counted_uom' => $request->uom ?? $item->product->base_uom, // Bisa kirim uom jika perlu konversi
            'is_zero_count' => $request->boolean('is_zero'),
            'updated_at' => now(),
        ]);

        // Opsional: Jika ini adalah validasi terakhir, ubah status task?
        // Biasanya manual finish via tombol "Complete Validation".

        return response()->json(['status' => 'success', 'message' => 'Item validated. Final count set.']);
    }


}
