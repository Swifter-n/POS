<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class OrderResource extends JsonResource
{
    /**
     * Ubah resource menjadi array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'type_order' => $this->type_order,
            'table_number' => $this->table_number,
            'customer_name' => $this->customer_name,
            'payment_method' => $this->payment_method,

            'sub_total' => (float) $this->sub_total,
            'tax' => (float) $this->tax,
            'discount' => (float) $this->discount,
            'total_price' => (float) $this->total_price,
            'total_items' => (int) $this->total_items,

            'guest_count' => (int) $this->guest_count,

            // Pastikan casting aman (jika null jadi 0)
            'points_earned' => (double) ($this->points_earned ?? 0),
            'points_redeemed' => (double) ($this->points_redeemed ?? 0),

            // Fix: Gunakan Closure agar tidak trigger query jika tidak diload
            'promo_code_applied' => $this->whenLoaded('promoCode', fn() => $this->promoCode?->code),

            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : now()->toIso8601String(),

            'member' => $this->whenLoaded('member', function() {
                if (!$this->member) return null;

                $memberId = $this->member->id;

                // A. Insight
                $lastVisit = '-';
                if ($this->member->last_transaction_at) {
                    $lastVisit = \Carbon\Carbon::parse($this->member->last_transaction_at)->diffForHumans();
                } else {
                    $lastOrder = Order::where('member_id', $memberId)
                        ->where('id', '!=', $this->id)
                        ->whereRaw("LOWER(status) IN ('paid', 'completed')")
                        ->latest()->first();
                    $lastVisit = $lastOrder ? $lastOrder->created_at->diffForHumans() : 'Baru bergabung';
                }

                $topItem = DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->join('products', 'order_items.product_id', '=', 'products.id')
                    ->where('orders.member_id', $memberId)
                    ->whereRaw("LOWER(orders.status) IN ('paid', 'completed')")
                    ->select('products.name', DB::raw('SUM(order_items.quantity) as total_qty'))
                    ->groupBy('products.id', 'products.name')
                    ->orderByDesc('total_qty')->first();

                $favProduct = $topItem ? $topItem->name : '-';
                $totalSpend = Order::where('member_id', $memberId)->whereRaw("LOWER(status) IN ('paid', 'completed')")->sum('total_price');

                // B. === FETCH AVAILABLE VOUCHERS ===
                $activeVouchers = $this->member->activeVouchers()
                    ->with('discountRule')
                    ->get()
                    ->filter(fn($v) => $v->isValid())
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
                // ===================================

                return [
                    'id' => $this->member->id,
                    'name' => $this->member->name,
                    'phone' => $this->member->phone ?? '-',
                    'email' => $this->member->email,
                    'tier' => $this->member->tier,
                    'points' => (double) $this->member->current_points,
                    'insight' => [
                        'last_visit' => $lastVisit,
                        'total_spend' => (double) $totalSpend,
                        'favorite_product' => $favProduct,
                    ],
                    // Masukkan ke response
                    'vouchers' => $activeVouchers
                ];
            }),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'applied_rules' => $this->applied_rules ?? [],

            'promo_code' => $this->whenLoaded('promoCode', function () {
                 if (!$this->promoCode) return null;
                 return [
                     'code' => $this->promoCode->code,
                     'name' => $this->promoCode->name
                 ];
            }),
        ];
    }
    private function generateDescription($rule)
    {
        if (!$rule) return "-";
        if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
        if ($rule->type == 'minimum_purchase') return "Min. Belanja";
        if ($rule->discount_type == 'percentage') return "Diskon {$rule->discount_value}%";
        return "Potongan Harga";
    }
}
