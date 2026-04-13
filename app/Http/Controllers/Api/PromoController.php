<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscountRule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PromoController extends Controller
{
    /**
     * GET /api/v1/pos/promos
     * Mengambil daftar promo aktif khusus untuk POS.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $now = Carbon::now();

        $promos = DiscountRule::where('business_id', $user->business_id)
            ->where('is_active', true)
            // Hanya promo yang berlaku untuk POS atau ALL
            ->whereIn('applicable_for', ['pos', 'all'])
            // Validasi tanggal
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            })
            ->orderBy('priority', 'desc') // Tampilkan prioritas tinggi duluan
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promos
        ]);
    }
}
