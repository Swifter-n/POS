<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReservationController extends Controller
{
    /**
     * Helper: Mendapatkan Outlet ID secara dinamis.
     * Menangani User biasa (outlet_id) dan User Polymorphic (locationable_id).
     */
    private function getOutletId($user)
    {
        // 1. Cek kolom standar
        if (!empty($user->outlet_id)) {
            return $user->outlet_id;
        }

        // 2. Cek relasi polymorphic (Owner/Staff khusus)
        // Perhatikan double backslash untuk namespace
        if ($user->locationable_type === 'App\\Models\\Outlet' && !empty($user->locationable_id)) {
            return $user->locationable_id;
        }

        return null;
    }

    /**
     * GET /api/v1/pos/reservations
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        if (!$outletId) {
             return response()->json(['success' => true, 'data' => []]);
        }

        // === PERBAIKAN LOGIKA QUERY ===
        $reservations = Reservation::where('outlet_id', $outletId)
            ->where(function ($query) {
                // KELOMPOK 1: Reservasi Aktif (Booked/Seated)
                $query->where(function ($q) {
                    $q->where('status', '!=', 'cancelled')
                      ->where('status', '!=', 'completed') 
                      // 🔥 PERBAIKAN: Gunakan \Carbon\Carbon::today() atau helper today()
                      ->whereDate('reservation_time', '>=', \Carbon\Carbon::today());
                })
                // KELOMPOK 2: Reservasi Batal (Cancelled)
                ->orWhere(function ($q) {
                    $q->where('status', 'cancelled')
                      ->where('updated_at', '>=', now()->subDay());
                });
            })
            ->with('table')
            ->orderBy('reservation_time', 'asc')
            ->get();
        // ==============================

        return response()->json(['success' => true, 'data' => $reservations]);
    }

    /**
     * POST /api/v1/pos/reservations
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        if (!$outletId) {
            return response()->json(['message' => 'User tidak terhubung ke outlet manapun.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'customer_name' => 'required|string',
            'guest_count' => 'required|integer|min:1',
            'reservation_time' => 'required|date',
            'table_id' => 'required|exists:tables,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $reservation = Reservation::create([
            'outlet_id' => $outletId,
            'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'guest_count' => $request->guest_count,
            'reservation_time' => $request->reservation_time,
            'table_id' => $request->table_id,
            'status' => 'booked',
            'notes' => $request->notes,
        ]);

        return response()->json(['success' => true, 'data' => $reservation], 201);
    }

    /**
     * POST /api/v1/pos/reservations/{id}/status
     */
        public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        $outletId = $this->getOutletId($user);

        $reservation = Reservation::where('outlet_id', $outletId)->find($id);

        if (!$reservation) return response()->json(['message' => 'Not found'], 404);

        $request->validate(['status' => 'required|string']);

        // Saat status berubah (misal ke 'cancelled'), updated_at otomatis berubah.
        // Ini yang digunakan oleh filter 'index' di atas.
        $reservation->update(['status' => $request->status]);

        return response()->json(['success' => true, 'data' => $reservation]);
    }

    public function cancelReservation($id) {
    DB::transaction(function () use ($id) {
        $reservation = Reservation::findOrFail($id);
        $reservation->update(['status' => 'cancelled']);

        // Jika tamu sudah duduk (seated), kembalikan status fisik meja
        if ($reservation->status === 'seated') {
            Table::where('id', $reservation->table_id)->update(['status' => 'available']);
        }
    });
    
    return response()->json(['message' => 'Reservasi berhasil dibatalkan']);
}
}
