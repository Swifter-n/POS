<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class SalesOrderDiscountService
{
    /**
     * Menghitung Diskon Khusus B2B / Sales Order.
     * Mewajibkan Customer Object.
     */
    public static function calculate(
        array $items,
        float $subTotal,
        int $businessId,
        Customer $customer // Wajib Customer B2B
    ): array
    {
        $totalDiscount = 0;
        $appliedRules = [];
        $pricingService = new PricingService();

        // Ambil Rule B2B (Sales Order / All)
        $rules = DiscountRule::where('business_id', $businessId)
            ->where('is_active', true)
            ->whereIn('applicable_for', ['sales_order', 'all'])
            ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->orderBy('priority', 'asc')
            ->get();

        $productIds = Arr::pluck($items, 'product_id');
        $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($rules as $rule) {
            // Validasi Syarat Customer (Channel, Priority, ID)
            // Jika rule butuh syarat tapi customer tidak memenuhi -> Skip
            if ($rule->customer_channel && $rule->customer_channel !== $customer->channel_id) continue;
            if ($rule->customer_id && $rule->customer_id !== $customer->id) continue;
            if ($rule->priority_level_id) {
                if (!$customer->priorityLevel || $customer->priorityLevel->id !== $rule->priority_level_id) continue;
            }

            $discountAmountForRule = 0;

            foreach ($items as $item) {
                $product = $productModels->get($item['product_id']);
                if (!$product) continue;

                $quantity = $item['quantity'];
                $uom = $item['uom'];

                // Gunakan PricingService untuk validasi rule B2B yang kompleks
                if ($pricingService->isRuleApplicable($rule, $customer, $product, $quantity, $uom)) {

                    $basePrice = $pricingService->getBasePrice($customer, $product);

                    $val = 0;
                    if ($rule->discount_type === 'percentage') {
                        $val = ($basePrice * $rule->discount_value / 100);
                    } else {
                        $val = $rule->discount_value;
                    }

                    $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
                    $conv = $uomData?->conversion_rate ?? 1.0;

                    $discountAmountForRule += ($val * $conv * $quantity);
                }
            }

            if ($discountAmountForRule > 0) {
                $totalDiscount += $discountAmountForRule;
                $appliedRules[] = $rule->name;
                if (!$rule->is_cumulative) break;
            }
        }

        return [
            'total_discount' => $totalDiscount,
            'applied_rules' => array_unique($appliedRules)
        ];
    }
}
