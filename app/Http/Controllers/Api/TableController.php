<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TableController extends Controller
{
    /**
     * Helper: Mendapatkan Outlet ID dari User secara dinamis.
     * Menangani kasus kolom 'outlet_id' vs 'locationable_id'.
     */
        private function getOutletId($user)
    {
        if (!empty($user->outlet_id)) {
            return $user->outlet_id;
        }
        if ($user->locationable_type === 'App\\Models\\Outlet' && !empty($user->locationable_id)) {
            return $user->locationable_id;
        }
        return null;
    }

    /**
     * GET /api/v1/pos/tables
     */

    public function index(Request $request) {
    $user = $request->user();
    $outletId = $this->getOutletId($user);

            if (!$outletId) {
            // Jika user tidak punya outlet, return kosong (bukan error)
            return response()->json(['success' => true, 'data' => []]);
        }

    $tables = Table::where('outlet_id', $outletId)
    ->orderBy('code', 'asc')
        ->get()
        ->append([
            'reservation_status', 
            'is_occupied', 
            'active_order_id', 
            'reserved_customer_name'
        ]);

    return response()->json(['success' => true, 'data' => $tables]);
}
    // public function index(Request $request)
    // {
    //     $user = $request->user();
    //     $outletId = $this->getOutletId($user);

    //     if (!$outletId) {
    //         // Jika user tidak punya outlet, return kosong (bukan error)
    //         return response()->json(['success' => true, 'data' => []]);
    //     }

    //     $tables = Table::where('outlet_id', $outletId)
    //         ->orderBy('code', 'asc')
    //         ->get();

    //     return response()->json([
    //         'success' => true,
    //         'data' => $tables
    //     ]);
    // }

    /**
     * POST /api/v1/pos/tables
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        if (!$outletId) {
            return response()->json(['message' => 'User tidak terhubung ke outlet manapun.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'code' => [
                'required',
                'string',
                // Validasi unik untuk outlet ini saja
                function ($attribute, $value, $fail) use ($outletId) {
                    if (Table::where('outlet_id', $outletId)->where('code', $value)->exists()) {
                        $fail('Nama meja sudah ada di outlet ini.');
                    }
                },
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $table = Table::create([
            'outlet_id' => $outletId,
            'code' => $request->code,
            'x_position' => 0,
            'y_position' => 0,
            'qr_content' => $request->code,
            'capacity' => $request->capacity,
        ]);

        return response()->json([
            'success' => true,
            'data' => $table
        ], 201);
    }

        /**
     * POST /api/v1/pos/tables/{id}/clear
     * Mengosongkan meja (Tamu Pulang) khusus untuk Quick Cart / Manual Clear.
     */
    public function clear(Request $request, $id)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        $table = Table::where('id', $id)
            ->where('outlet_id', $outletId)
            ->first();

        if (!$table) {
            return response()->json(['message' => 'Meja tidak ditemukan'], 404);
        }

        DB::transaction(function () use ($table) {
            // 1. Update Status Meja Fisik
            $table->update(['status' => 'available']);

            // 2. Selesaikan Reservasi 'seated' yang menggantung
            Reservation::where('table_id', $table->id)
                ->where('status', 'seated')
                ->update(['status' => 'completed']);
            
            // 3. Batalkan Reservasi 'booked' yang menggantung
            // Reservation::where('table_id', $table->id)
            //     ->whereDate('reservation_time', \Carbon\Carbon::today())
            //     ->where('status', 'booked')
            //     ->update(['status' => 'cancelled']);
        });

        return response()->json(['success' => true, 'message' => 'Meja berhasil dikosongkan.']);
    }

    /**
     * PUT /api/v1/pos/tables/{id}
     */
        public function update(Request $request, $id)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        // Pastikan meja milik outlet user ini
        $table = Table::where('outlet_id', $outletId)->find($id);

        if (!$table) {
            return response()->json(['message' => 'Meja tidak ditemukan atau akses ditolak'], 404);
        }

        $request->validate([
            'code' => 'sometimes|string',
            'x' => 'sometimes|numeric',
            'y' => 'sometimes|numeric',
        ]);

        if ($request->has('code')) {
            if ($table->code !== $request->code) {
                $exists = Table::where('outlet_id', $outletId)
                    ->where('code', $request->code)
                    ->exists();
                if ($exists) {
                    return response()->json(['message' => 'Nama meja sudah digunakan'], 422);
                }
            }
            $table->code = $request->code;
            $table->name = $request->code; // Sync name juga
        }

        if ($request->has('x')) $table->x_position = $request->x;
        if ($request->has('y')) $table->y_position = $request->y;
        // Handle nama field versi panjang juga jika frontend mengirimnya
        if ($request->has('x_position')) $table->x_position = $request->x_position;
        if ($request->has('y_position')) $table->y_position = $request->y_position;

        $table->save();

        return response()->json([
            'success' => true,
            'data' => $table
        ]);
    }

    /**
     * DELETE /api/v1/pos/tables/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        $table = Table::where('outlet_id', $outletId)->find($id);

        if (!$table) {
            return response()->json(['message' => 'Meja tidak ditemukan'], 404);
        }

        if ($table->is_occupied) {
             return response()->json(['message' => 'Meja sedang digunakan. Selesaikan order terlebih dahulu.'], 400);
        }

        $table->delete();

        return response()->json([
            'success' => true,
            'message' => 'Meja berhasil dihapus'
        ]);
    }

     /**
     * POST /api/v1/pos/tables/positions
     * Bulk update posisi meja.
     */
    public function updatePositions(Request $request)
    {
        $request->validate([
            'positions' => 'required|array',
            'positions.*.id' => 'required|exists:tables,id',
            'positions.*.x' => 'required|numeric',
            'positions.*.y' => 'required|numeric',
        ]);

        $positions = $request->positions;

        DB::transaction(function () use ($positions) {
            foreach ($positions as $pos) {
                // Idealnya validasi outlet_id juga di sini untuk keamanan ekstra
                $table = Table::find($pos['id']);
                if ($table) {
                    $table->update([
                        'x_position' => $pos['x'],
                        'y_position' => $pos['y'],
                    ]);
                }
            }
        });

        return response()->json(['success' => true, 'message' => 'Posisi meja berhasil disimpan.']);
    }


public function checkIn(Request $request, $id)
{
    $table = Table::find($id);
    if (!$table) {
        return response()->json(['success' => false, 'message' => 'Meja tidak ditemukan'], 404);
    }

    // Cari reservasi booked hari ini untuk meja ini
    $reservation = Reservation::where('table_id', $table->id)
        ->whereDate('reservation_time', \Carbon\Carbon::today())
        ->where('status', 'booked')
        ->first();

    if ($reservation) {
        $reservation->update(['status' => 'seated']);
        return response()->json([
            'success' => true, 
            'message' => 'Check-in berhasil. Selamat melayani!'
        ]);
    }

    return response()->json(['success' => false, 'message' => 'Tidak ada reservasi untuk meja ini'], 400);
}
    

}
