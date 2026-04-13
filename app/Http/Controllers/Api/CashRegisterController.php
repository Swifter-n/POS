<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterController extends Controller
{
    /**
     * Cek Status Shift User Saat Ini.
     * Frontend akan memanggil ini saat app dibuka.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        // Cari register yang masih OPEN milik user ini
        $register = CashRegister::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if (!$register) {
            return response()->json([
                'status' => 'closed',
                'message' => 'Anda belum membuka shift kasir.',
            ]);
        }

        return response()->json([
            'status' => 'open',
            'data' => $register
        ]);
    }

    /**
     * Buka Shift Baru (Open Register).
     */
    public function open(Request $request)
    {
        $request->validate([
            'opening_amount' => 'required|numeric|min:0',
        ]);

        $user = $request->user();

        // Cek apakah sudah ada shift terbuka?
        $existing = CashRegister::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Anda sudah memiliki shift yang aktif.'], 400);
        }

        DB::transaction(function () use ($request, $user) {
            // 1. Buat Header Shift
            $register = CashRegister::create([
                'business_id' => $user->business_id,
                // Asumsi user terikat outlet via polymorphic atau column
                'outlet_id' => $user->locationable_id ?? null,
                'user_id' => $user->id,
                'status' => 'open',
                'opening_amount' => $request->opening_amount,
                'opened_at' => now(),
            ]);

            // 2. Catat Log Transaksi (Modal Awal)
            CashRegisterTransaction::create([
                'cash_register_id' => $register->id,
                'amount' => $request->opening_amount,
                'transaction_type' => 'opening',
                'pay_method' => 'cash',
                'type' => 'credit', // Uang masuk laci
                'notes' => 'Modal Awal Kasir',
            ]);
        });

        return response()->json(['message' => 'Shift berhasil dibuka.', 'status' => 'open']);
    }

    /**
     * Hitung Rekapitulasi Sebelum Tutup (Pre-Close Report).
     * Method ini dipanggil saat kasir menekan tombol "Tutup Shift" untuk melihat ringkasan.
     */
    public function summary(Request $request)
    {
        $user = $request->user();

        // Cari shift aktif
        $register = CashRegister::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if (!$register) {
            return response()->json(['message' => 'Tidak ada shift aktif.'], 404);
        }

        // Ambil semua order yang terhubung ke shift ini (Paid Only)
        // Kolom cash_register_id sudah diisi oleh StoreOrderController::pay
        $orders = Order::where('cash_register_id', $register->id)
            ->where('status', 'paid')
            ->get();

        // 1. Hitung Total Uang Tunai Sistem (Modal Awal + Cash Sales)
        // Cash Sales = Order dengan metode 'cash'
        $cashSales = $orders->where('payment_method', 'cash')->sum('total_price');

        // Total Cash di Laci = Modal Awal + Penjualan Tunai
        // (Nanti bisa dikurangi jika ada fitur Expense/Payout cash)
        $expectedCashInDrawer = $register->opening_amount + $cashSales;

        // 2. Hitung Total Non-Tunai (Card/QRIS/Transfer)
        $cardSales = $orders->where('payment_method', '!=', 'cash')->sum('total_price');

        // 3. Hitung Total Poin yang Dipakai Customer
        $totalPointsRedeemed = $orders->sum('points_redeemed');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $register->id,
                'opened_at' => $register->opened_at,
                'opening_amount' => (float)$register->opening_amount,

                // Rincian Sales
                'cash_sales' => (float)$cashSales,
                'card_sales' => (float)$cardSales,

                // Uang yang seharusnya ada di laci
                'expected_cash' => (float)$expectedCashInDrawer,

                // Statistik lain
                'total_points_redeemed' => (float)$totalPointsRedeemed,
                'total_transactions' => $orders->count(),
            ]
        ]);
    }

    /**
     * Tutup Shift (Close Register).
     */
    public function close(Request $request)
    {
        $request->validate([
            'closing_amount' => 'required|numeric|min:0', // Uang fisik yang dihitung kasir
            'note' => 'nullable|string',
        ]);

        $user = $request->user();
        $register = CashRegister::where('user_id', $user->id)
            ->where('status', 'open')
            ->first();

        if (!$register) {
            return response()->json(['message' => 'Tidak ada shift aktif.'], 404);
        }

        // Hitung ulang total sistem untuk disimpan permanen (Snapshot)
        $orders = Order::where('cash_register_id', $register->id)
            ->where('status', 'paid')
            ->get();

        $cashSales = $orders->where('payment_method', 'cash')->sum('total_price');
        $cardSales = $orders->where('payment_method', '!=', 'cash')->sum('total_price');
        $pointsRedeemed = $orders->sum('points_redeemed');

        // Expected Cash di sistem
        $expectedCash = $register->opening_amount + $cashSales;

        // Update Register menjadi Closed
        $register->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closing_amount' => $request->closing_amount, // Input manual kasir
            'closing_note' => $request->note,

            // Snapshot Data Sistem (Agar report tidak berubah jika order diedit nanti)
            'total_cash_sales' => $cashSales,
            'total_card_sales' => $cardSales,
            'total_points_redeemed' => $pointsRedeemed,

            // Kolom helper (opsional, tergantung migrasi Anda)
            'total_cash' => $expectedCash,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift berhasil ditutup.',
            'data' => $register
        ]);
    }
}
// class CashRegisterController extends Controller
// {
//     /**
//      * Cek Status Shift User Saat Ini.
//      * Frontend akan memanggil ini saat app dibuka.
//      * Jika return null, Frontend harus memblokir akses ke halaman POS.
//      */
//     public function status(Request $request)
//     {
//         $user = $request->user();

//         // Cari register yang masih OPEN milik user ini
//         $register = CashRegister::where('user_id', $user->id)
//             ->where('outlet_id', $user->locationable_id)
//             ->where('status', 'open')
//             ->first();

//         if (!$register) {
//             return response()->json([
//                 'status' => 'closed',
//                 'message' => 'Anda belum membuka shift kasir.',
//             ]);
//         }

//         return response()->json([
//             'status' => 'open',
//             'data' => $register
//         ]);
//     }

//     /**
//      * Buka Shift Baru (Open Register).
//      */
//     public function open(Request $request)
//     {
//         $request->validate([
//             'opening_amount' => 'required|numeric|min:0',
//         ]);

//         $user = $request->user();

//         // Cek apakah sudah ada shift terbuka?
//         $existing = CashRegister::where('user_id', $user->id)
//             ->where('status', 'open')
//             ->first();

//         if ($existing) {
//             return response()->json(['message' => 'Anda sudah memiliki shift yang aktif.'], 400);
//         }

//         DB::transaction(function () use ($request, $user) {
//             // 1. Buat Header Shift
//             $register = CashRegister::create([
//                 'business_id' => $user->business_id,
//                 'outlet_id' => $user->locationable_id, // Asumsi user terikat outlet
//                 'user_id' => $user->id,
//                 'status' => 'open',
//                 'opening_amount' => $request->opening_amount,
//                 'opened_at' => now(),
//             ]);

//             // 2. Catat Log Transaksi (Modal Awal)
//             CashRegisterTransaction::create([
//                 'cash_register_id' => $register->id,
//                 'amount' => $request->opening_amount,
//                 'transaction_type' => 'opening',
//                 'pay_method' => 'cash',
//                 'type' => 'credit', // Uang masuk laci
//                 'notes' => 'Modal Awal Kasir',
//             ]);
//         });

//         return response()->json(['message' => 'Shift berhasil dibuka.', 'status' => 'open']);
//     }

//     /**
//      * Hitung Rekapitulasi Sebelum Tutup (Pre-Close Report).
//      * Ini dipanggil saat kasir klik tombol "Tutup Shift" untuk menampilkan ringkasan sistem.
//      */
//     public function summary(Request $request)
//     {
//         $user = $request->user();
//         $register = CashRegister::where('user_id', $user->id)->where('status', 'open')->first();

//         if (!$register) {
//             return response()->json(['message' => 'Tidak ada shift aktif.'], 404);
//         }

//         // Ambil semua order yang terhubung ke shift ini
//         // (Nanti kita update StoreOrderController agar menyimpan cash_register_id)
//         $orders = Order::where('cash_register_id', $register->id)
//             ->where('status', 'paid')
//             ->get();

//         // 1. Hitung Total Uang Tunai Sistem (Modal Awal + Cash Sales)
//         // Note: Total Cash Sales dihitung dari order yang metodenya 'cash'
//         $cashSales = $orders->where('payment_method', 'cash')->sum('total_price');

//         // Total Cash di Laci = Modal Awal + Cash Sales (Kurangi refund/expense jika ada nanti)
//         $expectedCashInDrawer = $register->opening_amount + $cashSales;

//         // 2. Hitung Total Non-Tunai (Card/QRIS)
//         $cardSales = $orders->where('payment_method', '!=', 'cash')->sum('total_price');

//         // 3. Hitung Total Poin yang Dipakai Customer (Fitur Loyalty!)
//         $totalPointsRedeemed = $orders->sum('points_redeemed'); // Poin
//         // $totalPointsValue = ... jika ingin nilai rupiahnya, perlu hitung dari discount logic

//         return response()->json([
//             'data' => [
//                 'opening_amount' => (float)$register->opening_amount,
//                 'cash_sales' => (float)$cashSales,
//                 'card_sales' => (float)$cardSales,
//                 'expected_cash' => (float)$expectedCashInDrawer,
//                 'total_points_redeemed' => (float)$totalPointsRedeemed,
//                 'total_orders' => $orders->count(),
//             ]
//         ]);
//     }

//     /**
//      * Tutup Shift (Close Register).
//      */
//     public function close(Request $request)
//     {
//         $request->validate([
//             'closing_amount' => 'required|numeric|min:0', // Uang fisik yang dihitung kasir
//             'note' => 'nullable|string',
//         ]);

//         $user = $request->user();
//         $register = CashRegister::where('user_id', $user->id)->where('status', 'open')->first();

//         if (!$register) {
//             return response()->json(['message' => 'Tidak ada shift aktif.'], 404);
//         }

//         // Hitung ulang total sistem untuk disimpan permanen
//         $orders = Order::where('cash_register_id', $register->id)->where('status', 'paid')->get();
//         $cashSales = $orders->where('payment_method', 'cash')->sum('total_price');
//         $cardSales = $orders->where('payment_method', '!=', 'cash')->sum('total_price');
//         $pointsRedeemed = $orders->sum('points_redeemed');

//         $expectedCash = $register->opening_amount + $cashSales;

//         $register->update([
//             'status' => 'closed',
//             'closed_at' => now(),
//             'closing_amount' => $request->closing_amount, // Input manual kasir
//             'closing_note' => $request->note,

//             // Snapshot Data Sistem
//             'total_cash' => $expectedCash, // Berapa uang yg SEHARUSNYA ada
//             'total_card_slips' => $cardSales,
//             'total_points_redeemed' => $pointsRedeemed,
//         ]);

//         return response()->json(['message' => 'Shift berhasil ditutup.', 'data' => $register]);
//     }
// }
