<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\DiscountRule;
use App\Models\Member;
use App\Models\MemberVoucher;
use App\Models\Order;
use App\Models\Product;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    /**
     * Helper: Mendapatkan Outlet ID secara dinamis.
     * Menangani User biasa (outlet_id) dan User Polymorphic (locationable_id).
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
     * GET /api/v1/pos/members/check
     * Mengecek status member berdasarkan Kode (No HP / QR Token).
     */
    public function check(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $searchCode = $request->code;

        // 1. Cari Member
        $member = Member::where('business_id', $user->business_id)
            ->where(function ($query) use ($searchCode) {
                $query->where('phone', $searchCode)
                      ->orWhere('qr_token', $searchCode);
            })
            ->first();

        if (!$member) {
            return response()->json(['message' => 'Member tidak ditemukan.'], 404);
        }

        // 2. Ambil Voucher
        $activeVouchers = MemberVoucher::where('member_id', $member->id)
            ->where('is_used', false) // Pastikan False
            ->where(function($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', now());
            })
            ->with('discountRule')
            ->get()
            ->filter(function ($voucher) {
                // Filter tambahan: Pastikan rule induknya juga aktif
                return $voucher->discountRule && $voucher->discountRule->is_active;
            })
            ->map(function ($voucher) {
                $rule = $voucher->discountRule;
                return [
                    'id' => $voucher->id,
                    'code' => $voucher->code,
                    'name' => $rule->name,
                    'description' => $this->generateDescription($rule),
                    'discount_type' => $rule->discount_type,
                    'discount_value' => (double)$rule->discount_value,
                    'min_purchase' => $rule->type === 'minimum_purchase'
                        ? json_decode($rule->condition_value, true)['amount'] ?? 0
                        : 0,
                    'valid_until' => $voucher->valid_until ? $voucher->valid_until->format('Y-m-d H:i') : null,
                ];
            })
            ->values();

        // === 3. HITUNG DATA CRM (INSIGHT) ===

        // A. Last Visit
        $lastVisit = '-';
        if ($member->last_transaction_at) {
            $lastVisit = \Carbon\Carbon::parse($member->last_transaction_at)->diffForHumans();
        } else {
            // Fallback: Cek manual ke tabel order
            $lastOrder = Order::where('member_id', $member->id)
                ->whereRaw("LOWER(status) IN ('paid', 'completed')")
                ->latest()
                ->first();
            if ($lastOrder) {
                $lastVisit = $lastOrder->created_at->diffForHumans();
                // Auto-fix data member
                $member->update(['last_transaction_at' => $lastOrder->created_at]);
            }
        }

        // B. Total Spend
        $totalSpend = Order::where('member_id', $member->id)
            ->whereRaw("LOWER(status) IN ('paid', 'completed')")
            ->sum('total_price');

        // C. Favorite Product
        // Query Agregasi: Cari product_id yang paling banyak dibeli (SUM quantity)
        // Filter hanya order yang sudah PAID
        $topItem = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.member_id', $member->id)
            ->whereRaw("LOWER(orders.status) IN ('paid', 'completed')")
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as total_qty'))
            ->groupBy('order_items.product_id')
            ->orderByDesc('total_qty')
            ->first();

        $favProductName = '-';
        if ($topItem && $topItem->product_id) {
            $product = Product::find($topItem->product_id);
            if ($product) {
                $favProductName = $product->name;
                // Opsional: Tambahkan jumlah pembelian
                // $favProductName .= " ({$topItem->total_qty}x)";
            }
        }

        // Jika belum ada data pembelian sama sekali
        if ($totalSpend == 0) {
            $favProductName = "Belum ada transaksi";
        }
        // ====================================

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'tier' => $member->tier,
                'points' => (double) $member->current_points,
                'vouchers' => $activeVouchers,
                'insight' => [
                    'last_visit' => $lastVisit,
                    'total_spend' => (double) $totalSpend,
                    'favorite_product' => $favProductName,
                ]
            ]
        ]);
    }
    // public function check(Request $request)
    // {
    //     $request->validate([
    //         'code' => 'required|string',
    //     ]);

    //     $user = $request->user();
    //     $searchCode = $request->code;

    //     // 1. Cari Member (By Phone atau QR Token)
    //     $member = Member::where('business_id', $user->business_id)
    //         ->where(function ($query) use ($searchCode) {
    //             $query->where('phone', $searchCode)
    //                   ->orWhere('qr_token', $searchCode);
    //         })
    //         ->first();

    //     if (!$member) {
    //         return response()->json(['message' => 'Member tidak ditemukan.'], 404);
    //     }

    //     // 2. Ambil Voucher yang AKTIF dan VALID
    //     $activeVouchers = $member->activeVouchers()
    //         ->with('discountRule')
    //         ->get()
    //         ->filter(function ($voucher) {
    //             return $voucher->isValid();
    //         })
    //         ->map(function ($voucher) {
    //             $rule = $voucher->discountRule;
    //             return [
    //                 'id' => $voucher->id,
    //                 'code' => $voucher->code,
    //                 'name' => $rule->name,
    //                 'description' => $this->generateDescription($rule),
    //                 'discount_type' => $rule->discount_type,
    //                 'discount_value' => (double)$rule->discount_value,
    //                 'min_purchase' => $rule->type === 'minimum_purchase'
    //                     ? json_decode($rule->condition_value, true)['amount'] ?? 0
    //                     : 0,
    //                 'valid_until' => $voucher->valid_until ? $voucher->valid_until->format('Y-m-d H:i') : null,
    //             ];
    //         })
    //         ->values();

    //     return response()->json([
    //         'success' => true,
    //         'data' => [
    //             'id' => $member->id,
    //             'name' => $member->name,
    //             'phone' => $member->phone,
    //             'tier' => $member->tier,
    //             'points' => (double) $member->current_points,
    //             'available_vouchers' => $activeVouchers,
    //         ]
    //     ]);
    // }

    /**
     * GET /api/v1/pos/members
     * Mengambil daftar member (bisa dengan search query).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Member::where('business_id', $user->business_id);

        // Fitur pencarian
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                // === PERBAIKAN: Gunakan ilike untuk PostgreSQL ===
                // ilike membuat pencarian menjadi case-insensitive (tidak peduli huruf besar/kecil)
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
                // =================================================
            });
        }

        $members = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $members->items(),
            'meta' => [
                'current_page' => $members->currentPage(),
                'last_page' => $members->lastPage(),
                'total' => $members->total(),
            ]
        ]);
    }

    /**
     * POST /api/v1/pos/members/register
     * Mendaftarkan member baru dari POS.
     */
    public function register(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => [
                'required',
                'string',
                'max:20',
                Rule::unique('members')->where(function ($query) use ($user) {
                    return $query->where('business_id', $user->business_id);
                })
            ],
            'email' => 'nullable|email|max:255',
            'dob' => 'nullable|date',
        ]);

        $member = Member::create([
            'business_id' => $user->business_id,
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'dob' => $request->dob,
            'current_points' => 0,
            'tier' => 'Silver',
            'qr_token' => 'MEM-' . time() . '-' . Str::random(5),
            'password' => Hash::make('123456'),
        ]);

        // === LOGIKA VOUCHER DINAMIS ===

        // 1. Ambil Setting ID Rule dari Database
        // Kita cari setting dengan type 'welcome_voucher_rule'
        $ruleSetting = BusinessSetting::where('business_id', $user->business_id)
            ->where('type', 'welcome_voucher_rule')
            ->first();

        // 2. Ambil Setting Durasi Hari (Opsional, default 7)
        $validitySetting = BusinessSetting::where('business_id', $user->business_id)
            ->where('type', 'welcome_voucher_days')
            ->first();

        $validDays = $validitySetting ? (int)$validitySetting->value : 7;

        if ($ruleSetting && $ruleSetting->value) {
            // Cari Rule berdasarkan ID yang disimpan di setting
            $welcomeRule = DiscountRule::find($ruleSetting->value);

            if ($welcomeRule) {
                // Issue Voucher
                VoucherService::issueVoucher($member, $welcomeRule, $validDays);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Member berhasil didaftarkan.',
            'data' => [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'tier' => $member->tier,
                'points' => (double) $member->current_points,
                'vouchers' => [],
            ]
        ], 201);
    }

    /**
     * PUT /api/v1/pos/members/{id}
     * Update data member.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $member = Member::where('business_id', $user->business_id)->find($id);

        if (!$member) {
            return response()->json(['message' => 'Member tidak ditemukan'], 404);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => [
                'required',
                'string',
                Rule::unique('members')->ignore($member->id)->where(function ($query) use ($user) {
                    return $query->where('business_id', $user->business_id);
                })
            ],
            'email' => 'nullable|email|max:255',
        ]);

        $member->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data member berhasil diperbarui',
            'data' => $member
        ]);
    }

    // Helper untuk deskripsi voucher
    private function generateDescription($rule)
    {
        if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
        if ($rule->type == 'minimum_purchase') return "Min. Belanja";
        if ($rule->discount_type == 'percentage') return "Diskon {$rule->discount_value}%";
        return "Potongan Harga";
    }
}
