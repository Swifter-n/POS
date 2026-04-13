<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\DiscountRule;
use App\Models\MemberVoucher;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promo;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class PosDiscountService
{
    /**
     * Helper: Decode JSON aman
     */
    private static function safeJsonDecode($value)
    {
        if (is_array($value)) return $value;
        if (empty($value)) return [];
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_string($decoded)) {
            $clean = trim($value, '"');
            $clean = str_replace('""', '"', $clean);
            $decoded = json_decode($clean, true);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function generateDescription($rule)
    {
        if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
        if ($rule->type == 'minimum_purchase') return "Min. Belanja";
        if ($rule->type == 'category_discount') return "Diskon Kategori";
        if ($rule->type == 'buy_x_get_y') return "Bundling";

        if ($rule->discount_type == 'percentage') return "Diskon " . (float)$rule->discount_value . "%";
        return "Potongan Harga";
    }

    /**
     * UI Helper: Get Promos for Slider (Katalog Promo)
     */
    public static function getApplicablePromosForProduct(Product $product, Outlet $outlet): array
    {
        $productId = $product->id;
        $productBrandId = $product->brand_id;
        $categoryId = $product->category_id;
        $now = Carbon::now();

        try {
            $rules = DiscountRule::where('business_id', $product->business_id)
                ->where('is_active', true)
                ->whereIn('applicable_for', ['pos', 'all'])
                ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
                ->orderBy('priority', 'asc')
                ->get();

            $matchedRules = [];

            foreach ($rules as $rule) {
                $isMatch = false;
                $condition = self::safeJsonDecode($rule->condition_value);

                if ($rule->type) {
                    switch ($rule->type) {
                        case 'bogo_same_item': if (($condition['product_id']??null) == $productId) $isMatch = true; break;
                        case 'category_discount': if (($condition['category_id']??null) == $categoryId) $isMatch = true; break;
                        case 'buy_x_get_y': if (($condition['buy_product_id']??null) == $productId || ($condition['get_product_id']??null) == $productId) $isMatch = true; break;
                        case 'minimum_purchase': $isMatch = true; break;
                    }
                } else {
                    if ($rule->product_id == $productId) $isMatch = true;
                    elseif ($rule->brand_id && $rule->brand_id == $productBrandId) $isMatch = true;
                }

                if ($isMatch) {
                    $matchedRules[] = [
                        'id' => $rule->id,
                        'name' => $rule->name,
                        'discount_type' => $rule->discount_type,
                        'discount_value' => (string)$rule->discount_value,
                        'type' => $rule->type,
                        'description' => self::generateDescription($rule),
                        'condition_value' => $condition,
                        'is_cumulative' => (bool)$rule->is_cumulative,
                    ];
                }
            }
            return $matchedRules;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Main Calculation Logic for POS
     * MODE: HYBRID (Auto Apply Public Rules + Manual Trigger Personal Vouchers)
     */
    public static function calculate(
        array $items,
        float $subTotal,
        int $businessId,
        ?string $promoCodeInput,
        $member,
        bool $usePoints = false,
        array $ignoredRules = []
    ): array
    {
        try {
            $now = Carbon::now();
            $memberId = is_object($member) ? ($member->id ?? 'Unknown') : 'Guest';

            // 1. Parsing Kode Promo Manual
            $requestedCodes = [];
            if (!empty($promoCodeInput)) {
                $rawCodes = explode(',', $promoCodeInput);
                $requestedCodes = array_unique(array_filter(array_map('trim', $rawCodes)));
            }

            // Normalisasi Ignored Rules
            $normalizedIgnoredRules = array_map(fn($r) => strtolower(trim($r)), $ignoredRules);

            Log::info("POS CALC | User: $memberId | Total: $subTotal | Codes: " . json_encode(array_values($requestedCodes)));

            $totalDiscount = 0;
            $appliedRules = [];
            $stopFurtherProcessing = false;
            $currentRunningTotal = $subTotal;

            // ---------------------------------------------------------
            // A. GLOBAL DISCOUNT
            // ---------------------------------------------------------
            $globalDiscountSetting = BusinessSetting::where('business_id', $businessId)
                ->where('type', 'discount')->where('status', true)->first();
            if ($globalDiscountSetting) {
                $appliedRules[] = 'Global Discount';
                if ($globalDiscountSetting->charge_type === 'percent') {
                    $val = ($currentRunningTotal * (float)$globalDiscountSetting->value) / 100;
                    $totalDiscount += $val;
                    $currentRunningTotal -= $val;
                } else {
                    $val = (float)$globalDiscountSetting->value;
                    $totalDiscount += $val;
                    $currentRunningTotal -= $val;
                }
            }

            // ---------------------------------------------------------
            // B. FETCH RULES
            // ---------------------------------------------------------
            $rules = DiscountRule::where('business_id', $businessId)
                ->where('is_active', true)
                ->whereIn('applicable_for', ['pos', 'all'])
                ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
                ->orderBy('priority', 'asc')
                ->get();

            // Cek ID Rule yang merupakan Voucher Milik Member (Untuk membedakan Public vs Personal)
            $memberVoucherRuleIds = [];
            if (is_object($member)) {
                $memberVoucherRuleIds = MemberVoucher::where('member_id', $member->id)
                    ->pluck('discount_rule_id')
                    ->unique()
                    ->toArray();
            }

            // Sortir Prioritas Manual
            if (!empty($requestedCodes)) {
                $rules = $rules->sortBy(function ($rule) use ($requestedCodes) {
                    foreach ($requestedCodes as $code) {
                        if (strcasecmp($rule->name, $code) === 0) return 0;
                    }
                    return 1;
                })->values();
            }

            // Validasi Kumulatif Manual
            $exclusiveRules = $rules->where('is_cumulative', false);
            $requestedExclusive = $exclusiveRules->filter(function($r) use ($requestedCodes) {
                 foreach ($requestedCodes as $c) { if (strcasecmp($r->name, $c) === 0) return true; }
                 return false;
            });
            if ($requestedExclusive->count() > 0 && count($requestedCodes) > 1) {
                $names = $requestedExclusive->pluck('name')->implode(', ');
                throw new \Exception("Promo '$names' bersifat eksklusif dan tidak dapat digabung.");
            }
            if ($requestedExclusive->count() > 1) {
                 throw new \Exception("Tidak dapat menggabungkan beberapa promo eksklusif sekaligus.");
            }

            $productIds = Arr::pluck($items, 'product_id');
            $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');
            $processedRuleNames = [];

            // ---------------------------------------------------------
            // C. HITUNG DISCOUNT RULES
            // ---------------------------------------------------------
            foreach ($rules as $rule) {
                // 1. Cek Ignored Rules (Tombol Silang)
                if (in_array(strtolower(trim($rule->name)), $normalizedIgnoredRules)) {
                    continue;
                }

                if ($stopFurtherProcessing) break;

                // 2. Cek Manual Request
                $isManualRequest = false;
                foreach ($requestedCodes as $c) { if (strcasecmp($rule->name, $c) === 0) $isManualRequest = true; }

                // === UPDATE KRUSIAL: CEK AUTO APPLY vs MANUAL ===
                // Jika ini adalah rule Voucher Member (Personal), JANGAN Auto Apply.
                // Hanya apply jika diminta manual ($isManualRequest)
                $isPersonalVoucherRule = in_array($rule->id, $memberVoucherRuleIds);

                if ($isPersonalVoucherRule && !$isManualRequest) {
                    // Skip Auto-Apply untuk Voucher Pribadi
                    // (Biarkan user memilih dari list voucher)
                    continue;
                }
                // ================================================

                $discountAmountForRule = 0;

                if ($rule->type) {
                    // A. POS Rules (Complex)
                    $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
                    if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
                        $discountAmountForRule = self::calculatePosRuleDiscount($rule, $items, $subTotal);
                    }
                } else {
                    // B. Item Level Rules
                    $requiresSpecific = $rule->customer_channel || $rule->priority_level_id || $rule->customer_id;
                    if ($requiresSpecific && !$member) continue;

                    foreach ($items as $item) {
                        $product = $productModels->get($item['product_id']);
                        if (!$product) continue;
                        $productMatch = !$rule->product_id || $rule->product_id == $product->id;
                        $brandMatch = !$rule->brand_id || $rule->brand_id == $product->brand_id;
                        if (!$productMatch || !$brandMatch) continue;

                        $quantity = $item['quantity'];
                        $uom = $item['uom'];
                        $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
                        $itemConv = $uomData?->conversion_rate ?? 1.0;
                        $qtyInBase = $quantity * $itemConv;

                        $ruleMinQty = $rule->min_quantity ?? 0;
                        if ($rule->min_quantity_uom) {
                             $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
                             $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
                        }

                        if ($qtyInBase >= $ruleMinQty) {
                            $basePrice = $product->price;
                            if ($rule->discount_type === 'percentage') {
                                $discPerItem = ($basePrice * $rule->discount_value / 100) * $itemConv;
                                $discountAmountForRule += ($discPerItem * $quantity);
                            } else {
                                if ($ruleMinQty > 1) {
                                    $bundles = floor($qtyInBase / $ruleMinQty);
                                    $discountAmountForRule += ($bundles * $rule->discount_value);
                                } else {
                                    $discountAmountForRule += ($rule->discount_value * $quantity);
                                }
                            }
                        }
                    }
                }

                if ($discountAmountForRule > 0) {
                    if (isset($rule->max_discount) && $rule->max_discount > 0) {
                        if ($discountAmountForRule > $rule->max_discount) $discountAmountForRule = $rule->max_discount;
                    }
                    if ($discountAmountForRule > $currentRunningTotal) $discountAmountForRule = $currentRunningTotal;

                    $totalDiscount += $discountAmountForRule;
                    $currentRunningTotal -= $discountAmountForRule;

                    $appliedRules[] = $rule->name;
                    $processedRuleNames[] = strtoupper($rule->name);

                    Log::info("Applied Rule: {$rule->name} ($discountAmountForRule)");

                    if (!$rule->is_cumulative) {
                        $stopFurtherProcessing = true;
                    }
                } else {
                    if ($isManualRequest) throw new \Exception("Syarat promo '{$rule->name}' tidak terpenuhi.");
                }
            }

            // ---------------------------------------------------------
            // D. SISA KODE MANUAL (Voucher Spesifik / Promo Umum)
            // ---------------------------------------------------------
            $remainingCodes = [];
            foreach ($requestedCodes as $reqCode) {
                if (in_array(strtolower(trim($reqCode)), $normalizedIgnoredRules)) continue;
                $isProcessed = false;
                foreach ($processedRuleNames as $procName) {
                    if (strcasecmp($reqCode, $procName) === 0) $isProcessed = true;
                }
                if (!$isProcessed) $remainingCodes[] = $reqCode;
            }

            foreach ($remainingCodes as $code) {
                if ($stopFurtherProcessing) break;
                $codeApplied = false;
                $discountAmountForCode = 0;

                $promo = Promo::whereRaw('LOWER(code) = ?', [strtolower($code)])
                    ->where('business_id', $businessId)
                    ->where(fn($q) => $q->whereNull('activated_at')->orWhere('activated_at', '<=', $now))
                    ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>=', $now))
                    ->first();

                $memberVoucher = null;
                if (!$promo) {
                    $memberVoucher = MemberVoucher::where('code', $code)
                        ->where('is_used', false)
                        ->with('discountRule')->first();
                    if ($memberVoucher) {
                         if (!$member || (is_object($member) && $member->id !== $memberVoucher->member_id)) {
                             $memberVoucher = null;
                         }
                    }
                }

                if ($memberVoucher && $memberVoucher->discountRule) {
                    $rule = $memberVoucher->discountRule;
                    if (!$rule->is_cumulative && count($appliedRules) > 0) {
                         throw new \Exception("Voucher '{$code}' tidak dapat digabung dengan promo lain.");
                    }
                    $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);

                    if ($rule->type) {
                        if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
                            $discountAmountForCode = self::calculatePosRuleDiscount($rule, $items, $subTotal);
                        }
                    } else {
                        foreach ($items as $item) {
                            $product = $productModels->get($item['product_id']);
                            if (!$product) continue;
                            if ($rule->product_id && $rule->product_id != $product->id) continue;
                            if ($rule->brand_id && $rule->brand_id != $product->brand_id) continue;

                            $quantity = $item['quantity'];
                            $uom = $item['uom'];
                            $basePrice = $product->price;

                            $ruleMinQty = $rule->min_quantity ?? 0;
                            if ($ruleMinQty > 0) {
                                $itemUomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
                                $itemConv = $itemUomData?->conversion_rate ?? 1;
                                $qtyInBase = $quantity * $itemConv;
                                if ($rule->min_quantity_uom) {
                                     $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
                                     $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
                                }
                                if ($qtyInBase < $ruleMinQty) continue;
                            }

                            $val = 0;
                            if ($rule->discount_type === 'percentage') {
                                $val = ((float)$basePrice * (float)$rule->discount_value / 100);
                            } else {
                                if ($ruleMinQty > 1) {
                                    $val = (float)$rule->discount_value / $ruleMinQty;
                                } else {
                                    $val = (float)$rule->discount_value;
                                }
                            }

                            $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
                            $conv = $uomData?->conversion_rate ?? 1;
                            $discountAmountForCode += ($val * $conv * $quantity);
                        }
                    }

                    if ($discountAmountForCode > 0) {
                        $codeApplied = true;
                        $appliedRules[] = "Voucher: {$memberVoucher->code}";
                    }
                }
                elseif ($promo) {
                    $isEligible = $subTotal >= ($promo->min_purchase ?? 0);
                    if ($isEligible) {
                        $val = $promo->discount_amount ?? $promo->discount_value ?? 0;
                        $discountAmountForCode = (float)$val;
                        $codeApplied = true;
                        $appliedRules[] = "Promo: {$promo->code}";
                    }
                }

                if ($codeApplied) {
                    if ($discountAmountForCode > $currentRunningTotal) $discountAmountForCode = $currentRunningTotal;
                    $totalDiscount += $discountAmountForCode;
                    $currentRunningTotal -= $discountAmountForCode;
                } else {
                    throw new \Exception("Syarat promo/voucher '$code' tidak terpenuhi.");
                }
            }

            // E. REDEEM POINTS
            $redeemablePoints = 0;
            $pointsValue = 0;
            if ($usePoints && $member && is_object($member) && $currentRunningTotal > 0) {
                $currentPoints = (float)$member->current_points;
                if ($currentPoints > 0) {
                    $exchangeRate = 1;
                    $rateSetting = BusinessSetting::where('business_id', $businessId)
                        ->where('type', 'point_exchange_rate')->first();
                    if ($rateSetting) $exchangeRate = (float)$rateSetting->value;

                    $maxMemberValue = $currentPoints * $exchangeRate;
                    $redeemValue = min($maxMemberValue, $currentRunningTotal);
                    $redeemablePoints = ceil($redeemValue / $exchangeRate);
                    $pointsValue = $redeemablePoints * $exchangeRate;

                    if ($pointsValue > $currentRunningTotal) $pointsValue = $currentRunningTotal;

                    $totalDiscount += $pointsValue;
                    $currentRunningTotal -= $pointsValue;
                    $appliedRules[] = "Redeem: $redeemablePoints Poin";
                }
            }

            if ($totalDiscount > $subTotal) $totalDiscount = $subTotal;

            return [
                'total_discount' => $totalDiscount,
                'applied_rules' => array_unique($appliedRules),
                'points_redeemed' => $redeemablePoints,
                'point_value' => $pointsValue,
            ];

        } catch (\Throwable $e) {
            Log::error("POS CALC ERROR: " . $e->getMessage());
            throw $e;
        }
    }

    // Helper POS Rules
    private static function isPosRuleApplicable(DiscountRule $rule, array $items, float $subTotal): bool {
        $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
        if (!$condition) return false;
        switch ($rule->type) {
            case 'minimum_purchase': return $subTotal >= (float)($condition['amount'] ?? 0);
            case 'bogo_same_item':
                $productId = $condition['product_id'] ?? null;
                $reqQty = ($condition['buy_quantity'] ?? 1) + ($condition['get_quantity'] ?? 1);
                $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
                return $item && $item['quantity'] >= $reqQty;
            case 'category_discount':
                $catId = $condition['category_id'] ?? null;
                if(!$catId) return false;
                $pIds = Arr::pluck($items, 'product_id');
                return Product::whereIn('id', $pIds)->where('category_id', $catId)->exists();
            case 'buy_x_get_y':
                $buyId = $condition['buy_product_id'] ?? null;
                $getId = $condition['get_product_id'] ?? null;
                $hasBuy = Arr::first($items, fn ($i) => $i['product_id'] == $buyId);
                $hasGet = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
                return $hasBuy && $hasGet;
            default: return false;
        }
    }

    private static function calculatePosRuleDiscount(DiscountRule $rule, array $items, float $subTotal): float {
        $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
        switch ($rule->type) {
            case 'minimum_purchase':
                $thresholdAmount = (float)($condition['amount'] ?? 0);
                if ($rule->discount_type === 'percentage') {
                    $calcFromTotal = isset($condition['calculate_from_total']) && $condition['calculate_from_total'] === true;
                    if ($calcFromTotal || $thresholdAmount <= 0) {
                        return ($subTotal * (float)$rule->discount_value) / 100;
                    } else {
                        return ($thresholdAmount * (float)$rule->discount_value) / 100;
                    }
                }
                return (float)$rule->discount_value;
            case 'bogo_same_item':
                $productId = $condition['product_id'] ?? null;
                $buyQty = (int)($condition['buy_quantity'] ?? 1);
                $getQty = (int)($condition['get_quantity'] ?? 1);
                $totalOfferUnit = $buyQty + $getQty;
                $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
                if (!$item) return 0;
                $offers = floor($item['quantity'] / $totalOfferUnit);
                return $offers * $getQty * (float)($item['price'] ?? 0);
            case 'category_discount':
                $categoryId = $condition['category_id'] ?? null;
                if (!$categoryId) return 0;
                $pIds = Product::where('category_id', $categoryId)->pluck('id')->toArray();
                $totalForCat = collect($items)->whereIn('product_id', $pIds)->sum('total');
                if ($rule->discount_type === 'percentage') {
                    return ($totalForCat * (float)$rule->discount_value) / 100;
                }
                return (float)$rule->discount_value;
            case 'buy_x_get_y':
                $getId = $condition['get_product_id'] ?? null;
                $item = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
                if (!$item) return 0;
                return ($rule->discount_type === 'percentage') ? ((float)($item['price'] ?? 0) * (float)$rule->discount_value) / 100 : (float)$rule->discount_value;
            default: return 0;
        }
    }
}
// class PosDiscountService
// {
//     private static function safeJsonDecode($value)
//     {
//         if (is_array($value)) return $value;
//         if (empty($value)) return [];
//         $decoded = json_decode($value, true);
//         if (json_last_error() !== JSON_ERROR_NONE || is_string($decoded)) {
//             $clean = trim($value, '"');
//             $clean = str_replace('""', '"', $clean);
//             $decoded = json_decode($clean, true);
//         }
//         return is_array($decoded) ? $decoded : [];
//     }

//     private static function generateDescription($rule)
//     {
//         if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
//         if ($rule->type == 'minimum_purchase') return "Min. Belanja";
//         if ($rule->type == 'category_discount') return "Diskon Kategori";
//         if ($rule->type == 'buy_x_get_y') return "Bundling";
//         if ($rule->discount_type == 'percentage') return "Diskon " . (float)$rule->discount_value . "%";
//         return "Potongan Harga";
//     }

//     public static function getApplicablePromosForProduct(Product $product, Outlet $outlet): array
//     {
//         $productId = $product->id;
//         $productBrandId = $product->brand_id;
//         $categoryId = $product->category_id;
//         $now = Carbon::now();

//         try {
//             $rules = DiscountRule::where('business_id', $product->business_id)
//                 ->where('is_active', true)
//                 ->whereIn('applicable_for', ['pos', 'all'])
//                 ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                 ->orderBy('priority', 'asc')
//                 ->get();

//             $matchedRules = [];

//             foreach ($rules as $rule) {
//                 $isMatch = false;
//                 $condition = self::safeJsonDecode($rule->condition_value);

//                 if ($rule->type) {
//                     switch ($rule->type) {
//                         case 'bogo_same_item': if (($condition['product_id']??null) == $productId) $isMatch = true; break;
//                         case 'category_discount': if (($condition['category_id']??null) == $categoryId) $isMatch = true; break;
//                         case 'buy_x_get_y': if (($condition['buy_product_id']??null) == $productId || ($condition['get_product_id']??null) == $productId) $isMatch = true; break;
//                         case 'minimum_purchase': $isMatch = true; break;
//                     }
//                 } else {
//                     if ($rule->product_id == $productId) $isMatch = true;
//                     elseif ($rule->brand_id && $rule->brand_id == $productBrandId) $isMatch = true;
//                 }

//                 if ($isMatch) {
//                     $matchedRules[] = [
//                         'id' => $rule->id,
//                         'name' => $rule->name,
//                         'discount_type' => $rule->discount_type,
//                         'discount_value' => (string)$rule->discount_value,
//                         'type' => $rule->type,
//                         'description' => self::generateDescription($rule),
//                         'condition_value' => $condition,
//                         'is_cumulative' => (bool)$rule->is_cumulative,
//                     ];
//                 }
//             }
//             return $matchedRules;
//         } catch (\Exception $e) {
//             return [];
//         }
//     }

//     public static function calculate(
//         array $items,
//         float $subTotal,
//         int $businessId,
//         ?string $promoCodeInput,
//         $member,
//         bool $usePoints = false,
//         array $ignoredRules = []
//     ): array
//     {
//         try {
//             $now = Carbon::now();
//             $memberId = is_object($member) ? ($member->id ?? 'Unknown') : 'Guest';

//             $requestedCodes = [];
//             if (!empty($promoCodeInput)) {
//                 $rawCodes = explode(',', $promoCodeInput);
//                 $requestedCodes = array_unique(array_filter(array_map('trim', $rawCodes)));
//             }

//             // === LOG PARAMETER ===
//             // Pastikan $usePoints bernilai true di log
//             Log::info("POS CALC | User: $memberId | Total: $subTotal | UsePoints: " . ($usePoints ? 'TRUE' : 'FALSE'));

//             $totalDiscount = 0;
//             $appliedRules = [];
//             $stopFurtherProcessing = false;
//             $currentRunningTotal = $subTotal;

//             // A. GLOBAL DISCOUNT
//             $globalDiscountSetting = BusinessSetting::where('business_id', $businessId)
//                 ->where('type', 'discount')->where('status', true)->first();
//             if ($globalDiscountSetting) {
//                 $appliedRules[] = 'Global Discount';
//                 if ($globalDiscountSetting->charge_type === 'percent') {
//                     $val = ($currentRunningTotal * (float)$globalDiscountSetting->value) / 100;
//                     $totalDiscount += $val;
//                     $currentRunningTotal -= $val;
//                 } else {
//                     $val = (float)$globalDiscountSetting->value;
//                     $totalDiscount += $val;
//                     $currentRunningTotal -= $val;
//                 }
//             }

//             // B. FETCH RULES
//             $rules = DiscountRule::where('business_id', $businessId)
//                 ->where('is_active', true)
//                 ->whereIn('applicable_for', ['pos', 'all'])
//                 ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                 ->orderBy('priority', 'asc')
//                 ->get();

//             if (!empty($requestedCodes)) {
//                 $rules = $rules->sortBy(function ($rule) use ($requestedCodes) {
//                     foreach ($requestedCodes as $code) {
//                         if (strcasecmp($rule->name, $code) === 0) return 0;
//                     }
//                     return 1;
//                 })->values();
//             }

//             $exclusiveRules = $rules->where('is_cumulative', false);
//             $requestedExclusive = $exclusiveRules->filter(function($r) use ($requestedCodes) {
//                  foreach ($requestedCodes as $c) { if (strcasecmp($r->name, $c) === 0) return true; }
//                  return false;
//             });

//             if ($requestedExclusive->count() > 0 && count($requestedCodes) > 1) {
//                 $names = $requestedExclusive->pluck('name')->implode(', ');
//                 throw new \Exception("Promo '$names' bersifat eksklusif dan tidak dapat digabung dengan promo lain.");
//             }
//             if ($requestedExclusive->count() > 1) {
//                  throw new \Exception("Tidak dapat menggabungkan beberapa promo eksklusif sekaligus.");
//             }

//             $productIds = Arr::pluck($items, 'product_id');
//             $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');
//             $processedRuleNames = [];

//             // C. HITUNG RULES
//             foreach ($rules as $rule) {
//                 if (in_array($rule->name, $ignoredRules)) continue;
//                 if ($stopFurtherProcessing) break;

//                 $discountAmountForRule = 0;
//                 $isManualRequest = false;
//                 foreach ($requestedCodes as $c) { if (strcasecmp($rule->name, $c) === 0) $isManualRequest = true; }

//                 if ($rule->type) {
//                     $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
//                     if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                         $discountAmountForRule = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                     }
//                 } else {
//                     $requiresSpecific = $rule->customer_channel || $rule->priority_level_id || $rule->customer_id;
//                     if ($requiresSpecific && !$member) continue;

//                     foreach ($items as $item) {
//                         $product = $productModels->get($item['product_id']);
//                         if (!$product) continue;
//                         $productMatch = !$rule->product_id || $rule->product_id == $product->id;
//                         $brandMatch = !$rule->brand_id || $rule->brand_id == $product->brand_id;
//                         if (!$productMatch || !$brandMatch) continue;

//                         $quantity = $item['quantity'];
//                         $uom = $item['uom'];
//                         $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                         $itemConv = $uomData?->conversion_rate ?? 1.0;
//                         $qtyInBase = $quantity * $itemConv;

//                         $ruleMinQty = $rule->min_quantity ?? 0;
//                         if ($rule->min_quantity_uom) {
//                              $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
//                              $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
//                         }

//                         if ($qtyInBase >= $ruleMinQty) {
//                             $basePrice = $product->price;
//                             if ($rule->discount_type === 'percentage') {
//                                 $discPerItem = ($basePrice * $rule->discount_value / 100) * $itemConv;
//                                 $discountAmountForRule += ($discPerItem * $quantity);
//                             } else {
//                                 if ($ruleMinQty > 1) {
//                                     $bundles = floor($qtyInBase / $ruleMinQty);
//                                     $discountAmountForRule += ($bundles * $rule->discount_value);
//                                 } else {
//                                     $discountAmountForRule += ($rule->discount_value * $quantity);
//                                 }
//                             }
//                         }
//                     }
//                 }

//                 if ($discountAmountForRule > 0) {
//                     if (isset($rule->max_discount) && $rule->max_discount > 0) {
//                         if ($discountAmountForRule > $rule->max_discount) $discountAmountForRule = $rule->max_discount;
//                     }
//                     if ($discountAmountForRule > $currentRunningTotal) $discountAmountForRule = $currentRunningTotal;

//                     $totalDiscount += $discountAmountForRule;
//                     $currentRunningTotal -= $discountAmountForRule;

//                     $appliedRules[] = $rule->name;
//                     $processedRuleNames[] = strtoupper($rule->name);

//                     if (!$rule->is_cumulative) $stopFurtherProcessing = true;
//                 } else {
//                     if ($isManualRequest) throw new \Exception("Syarat promo '{$rule->name}' tidak terpenuhi.");
//                 }
//             }

//             // D. SISA KODE MANUAL
//             $remainingCodes = [];
//             foreach ($requestedCodes as $reqCode) {
//                 if (in_array($reqCode, $ignoredRules)) continue;
//                 $isProcessed = false;
//                 foreach ($processedRuleNames as $procName) {
//                     if (strcasecmp($reqCode, $procName) === 0) $isProcessed = true;
//                 }
//                 if (!$isProcessed) $remainingCodes[] = $reqCode;
//             }

//             if (!$stopFurtherProcessing) {
//                 foreach ($remainingCodes as $code) {
//                     $codeApplied = false;
//                     $discountAmountForCode = 0;

//                     $promo = Promo::whereRaw('LOWER(code) = ?', [strtolower($code)])
//                         ->where('business_id', $businessId)
//                         ->where(fn($q) => $q->whereNull('activated_at')->orWhere('activated_at', '<=', $now))
//                         ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>=', $now))
//                         ->first();

//                     $memberVoucher = null;
//                     if (!$promo) {
//                         $memberVoucher = MemberVoucher::where('code', $code)
//                             ->where('is_used', false)
//                             ->with('discountRule')->first();
//                         if ($memberVoucher) {
//                              if (!$member || (is_object($member) && $member->id !== $memberVoucher->member_id)) {
//                                  Log::warning("Voucher Owner Mismatch: $code");
//                                  $memberVoucher = null;
//                              }
//                         }
//                     }

//                     if ($memberVoucher && $memberVoucher->discountRule) {
//                         $rule = $memberVoucher->discountRule;
//                         if (!$rule->is_cumulative && count($appliedRules) > 0) {
//                              throw new \Exception("Voucher '{$code}' tidak dapat digabung dengan promo lain.");
//                         }
//                         $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);

//                         if ($rule->type) {
//                             if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                                 $discountAmountForCode = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                             }
//                         } else {
//                             foreach ($items as $item) {
//                                 $product = $productModels->get($item['product_id']);
//                                 if (!$product) continue;
//                                 if ($rule->product_id && $rule->product_id != $product->id) continue;
//                                 if ($rule->brand_id && $rule->brand_id != $product->brand_id) continue;

//                                 $quantity = $item['quantity'];
//                                 $uom = $item['uom'];
//                                 $basePrice = $product->price;

//                                 $ruleMinQty = $rule->min_quantity ?? 0;
//                                 if ($ruleMinQty > 0) {
//                                     $itemUomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                     $itemConv = $itemUomData?->conversion_rate ?? 1;
//                                     $qtyInBase = $quantity * $itemConv;

//                                     if ($rule->min_quantity_uom) {
//                                          $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
//                                          $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
//                                     }
//                                     if ($qtyInBase < $ruleMinQty) continue;
//                                 }

//                                 $val = 0;
//                                 if ($rule->discount_type === 'percentage') {
//                                     $val = ((float)$basePrice * (float)$rule->discount_value / 100);
//                                 } else {
//                                     if ($ruleMinQty > 1) {
//                                         $bundles = floor($qtyInBase / $ruleMinQty);
//                                         $val = (float)$rule->discount_value / $ruleMinQty;
//                                     } else {
//                                         $val = (float)$rule->discount_value;
//                                     }
//                                 }

//                                 $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                 $conv = $uomData?->conversion_rate ?? 1;
//                                 $discountAmountForCode += ($val * $conv * $quantity);
//                             }
//                         }

//                         if ($discountAmountForCode > 0) {
//                             $codeApplied = true;
//                             $appliedRules[] = "Voucher: {$memberVoucher->code}";
//                         }
//                     }
//                     elseif ($promo) {
//                         $isEligible = $subTotal >= ($promo->min_purchase ?? 0);
//                         if ($isEligible) {
//                             $val = $promo->discount_amount ?? $promo->discount_value ?? 0;
//                             $discountAmountForCode = (float)$val;
//                             $codeApplied = true;
//                             $appliedRules[] = "Promo: {$promo->code}";
//                         }
//                     }

//                     if ($codeApplied) {
//                         if ($discountAmountForCode > $currentRunningTotal) $discountAmountForCode = $currentRunningTotal;
//                         $totalDiscount += $discountAmountForCode;
//                         $currentRunningTotal -= $discountAmountForCode;
//                     } else {
//                         throw new \Exception("Syarat promo/voucher '$code' tidak terpenuhi.");
//                     }
//                 }
//             }

//             // ---------------------------------------------------------
//             // E. REDEEM POINTS (DEBUGGED)
//             // ---------------------------------------------------------
//             $redeemablePoints = 0;
//             $pointsValue = 0;

//             // Logika debug untuk poin
//             if ($usePoints) {
//                  Log::info(">>> POINT REDEEM CHECK <<<");
//                  if (!$member) Log::info(" - No Member Object");
//                  if ($member) Log::info(" - Member Pts: " . $member->current_points);
//                  Log::info(" - Remaining Bill: $currentRunningTotal");
//             }

//             if ($usePoints && $member && is_object($member) && $currentRunningTotal > 0) {
//                 $currentPoints = (float)$member->current_points;

//                 if ($currentPoints > 0) {
//                     $exchangeRate = 1; // Default 1 Poin = Rp 1
//                     $rateSetting = BusinessSetting::where('business_id', $businessId)
//                         ->where('type', 'point_exchange_rate')->first();

//                     if ($rateSetting) {
//                         $exchangeRate = (float)$rateSetting->value;
//                         Log::info(" - Rate Found: $exchangeRate");
//                     }

//                     $maxMemberValue = $currentPoints * $exchangeRate;

//                     // Hitung berapa Rupiah yang bisa dipotong
//                     $redeemValue = min($maxMemberValue, $currentRunningTotal);

//                     // Hitung berapa Poin yang ditarik
//                     $redeemablePoints = ceil($redeemValue / $exchangeRate);
//                     $pointsValue = $redeemablePoints * $exchangeRate;

//                     // Safety cap
//                     if ($pointsValue > $currentRunningTotal) $pointsValue = $currentRunningTotal;

//                     $totalDiscount += $pointsValue;
//                     $currentRunningTotal -= $pointsValue;

//                     $appliedRules[] = "Redeem: $redeemablePoints Poin";

//                     Log::info(">>> REDEEM SUCCESS: $redeemablePoints Pts -> Rp $pointsValue");
//                 } else {
//                     Log::info(" - Zero Points");
//                 }
//             }

//             if ($totalDiscount > $subTotal) $totalDiscount = $subTotal;

//             Log::info("FINAL DISCOUNT: $totalDiscount");
//             return [
//                 'total_discount' => $totalDiscount,
//                 'applied_rules' => array_unique($appliedRules),
//                 'points_redeemed' => $redeemablePoints ?? 0,
//                 'point_value' => $pointsValue ?? 0,
//             ];

//         } catch (\Throwable $e) {
//             Log::error("POS CALC ERROR: " . $e->getMessage());
//             throw $e;
//         }
//     }

//     // ... (Helper methods isPosRuleApplicable & calculatePosRuleDiscount TETAP SAMA) ...
//     private static function isPosRuleApplicable(DiscountRule $rule, array $items, float $subTotal): bool {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
//         if (!$condition) return false;
//         switch ($rule->type) {
//             case 'minimum_purchase': return $subTotal >= (float)($condition['amount'] ?? 0);
//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $reqQty = ($condition['buy_quantity'] ?? 1) + ($condition['get_quantity'] ?? 1);
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
//                 return $item && $item['quantity'] >= $reqQty;
//             case 'category_discount':
//                 $catId = $condition['category_id'] ?? null;
//                 if(!$catId) return false;
//                 $pIds = Arr::pluck($items, 'product_id');
//                 return Product::whereIn('id', $pIds)->where('category_id', $catId)->exists();
//             case 'buy_x_get_y':
//                 $buyId = $condition['buy_product_id'] ?? null;
//                 $getId = $condition['get_product_id'] ?? null;
//                 $hasBuy = Arr::first($items, fn ($i) => $i['product_id'] == $buyId);
//                 $hasGet = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
//                 return $hasBuy && $hasGet;
//             default: return false;
//         }
//     }

//     private static function calculatePosRuleDiscount(DiscountRule $rule, array $items, float $subTotal): float {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
//         switch ($rule->type) {
//             case 'minimum_purchase':
//                 $thresholdAmount = (float)($condition['amount'] ?? 0);
//                 if ($rule->discount_type === 'percentage') {
//                     $calcFromTotal = isset($condition['calculate_from_total']) && $condition['calculate_from_total'] === true;
//                     if ($calcFromTotal || $thresholdAmount <= 0) {
//                         return ($subTotal * (float)$rule->discount_value) / 100;
//                     } else {
//                         return ($thresholdAmount * (float)$rule->discount_value) / 100;
//                     }
//                 }
//                 return (float)$rule->discount_value;

//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $buyQty = (int)($condition['buy_quantity'] ?? 1);
//                 $getQty = (int)($condition['get_quantity'] ?? 1);
//                 $totalOfferUnit = $buyQty + $getQty;
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
//                 if (!$item) return 0;
//                 $offers = floor($item['quantity'] / $totalOfferUnit);
//                 return $offers * $getQty * (float)($item['price'] ?? 0);

//             case 'category_discount':
//                 $categoryId = $condition['category_id'] ?? null;
//                 if (!$categoryId) return 0;
//                 $pIds = Product::where('category_id', $categoryId)->pluck('id')->toArray();
//                 $totalForCat = collect($items)->whereIn('product_id', $pIds)->sum('total');
//                 if ($rule->discount_type === 'percentage') {
//                     return ($totalForCat * (float)$rule->discount_value) / 100;
//                 }
//                 return (float)$rule->discount_value;

//             case 'buy_x_get_y':
//                 $getId = $condition['get_product_id'] ?? null;
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
//                 if (!$item) return 0;
//                 return ($rule->discount_type === 'percentage') ? ((float)($item['price'] ?? 0) * (float)$rule->discount_value) / 100 : (float)$rule->discount_value;

//             default: return 0;
//         }
//     }
// }
// class PosDiscountService
// {
//     /**
//      * Helper: Decode JSON aman
//      */
//     private static function safeJsonDecode($value)
//     {
//         if (is_array($value)) return $value;
//         if (empty($value)) return [];
//         $decoded = json_decode($value, true);
//         if (json_last_error() !== JSON_ERROR_NONE || is_string($decoded)) {
//             $clean = trim($value, '"');
//             $clean = str_replace('""', '"', $clean);
//             $decoded = json_decode($clean, true);
//         }
//         return is_array($decoded) ? $decoded : [];
//     }

//     private static function generateDescription($rule)
//     {
//         if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
//         if ($rule->type == 'minimum_purchase') return "Min. Belanja";
//         if ($rule->type == 'category_discount') return "Diskon Kategori";
//         if ($rule->type == 'buy_x_get_y') return "Bundling";

//         if ($rule->discount_type == 'percentage') return "Diskon " . (float)$rule->discount_value . "%";
//         return "Potongan Harga";
//     }

//     /**
//      * UI Helper: Get Promos for Slider (Katalog Promo)
//      */
//     public static function getApplicablePromosForProduct(Product $product, Outlet $outlet): array
//     {
//         $productId = $product->id;
//         $productBrandId = $product->brand_id;
//         $categoryId = $product->category_id;
//         $now = Carbon::now();

//         try {
//             $rules = DiscountRule::where('business_id', $product->business_id)
//                 ->where('is_active', true)
//                 ->whereIn('applicable_for', ['pos', 'all'])
//                 ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                 ->orderBy('priority', 'asc')
//                 ->get();

//             $matchedRules = [];

//             foreach ($rules as $rule) {
//                 $isMatch = false;
//                 $condition = self::safeJsonDecode($rule->condition_value);

//                 if ($rule->type) {
//                     switch ($rule->type) {
//                         case 'bogo_same_item': if (($condition['product_id']??null) == $productId) $isMatch = true; break;
//                         case 'category_discount': if (($condition['category_id']??null) == $categoryId) $isMatch = true; break;
//                         case 'buy_x_get_y': if (($condition['buy_product_id']??null) == $productId || ($condition['get_product_id']??null) == $productId) $isMatch = true; break;
//                         case 'minimum_purchase': $isMatch = true; break;
//                     }
//                 } else {
//                     if ($rule->product_id == $productId) $isMatch = true;
//                     elseif ($rule->brand_id && $rule->brand_id == $productBrandId) $isMatch = true;
//                 }

//                 if ($isMatch) {
//                     $matchedRules[] = [
//                         'id' => $rule->id,
//                         'name' => $rule->name,
//                         'discount_type' => $rule->discount_type,
//                         'discount_value' => (string)$rule->discount_value,
//                         'type' => $rule->type,
//                         'description' => self::generateDescription($rule),
//                         'condition_value' => $condition,
//                         'is_cumulative' => (bool)$rule->is_cumulative,
//                     ];
//                 }
//             }
//             return $matchedRules;
//         } catch (\Exception $e) {
//             return [];
//         }
//     }

//     /**
//      * Main Calculation Logic for POS
//      * MODE: HYBRID (Auto Apply + Manual Trigger) + IGNORED RULES
//      */
//     public static function calculate(
//         array $items,
//         float $subTotal,
//         int $businessId,
//         ?string $promoCodeInput,
//         $member,
//         bool $usePoints = false,
//         array $ignoredRules = [] // <-- UPDATE: Parameter Baru
//     ): array
//     {
//         try {
//             $now = Carbon::now();
//             $memberId = is_object($member) ? ($member->id ?? 'Unknown') : 'Guest';

//             // 1. Parsing Kode Promo
//             $requestedCodes = [];
//             if (!empty($promoCodeInput)) {
//                 $rawCodes = explode(',', $promoCodeInput);
//                 $requestedCodes = array_unique(array_filter(array_map('trim', $rawCodes)));
//             }

//             Log::info("POS CALC START | User: $memberId | Total: $subTotal | Codes: " . json_encode(array_values($requestedCodes)) . " | Ignore: " . json_encode($ignoredRules));

//             $totalDiscount = 0;
//             $appliedRules = [];
//             $stopFurtherProcessing = false;

//             // Logic Running Total
//             $currentRunningTotal = $subTotal;

//             // ---------------------------------------------------------
//             // A. GLOBAL DISCOUNT
//             // ---------------------------------------------------------
//             $globalDiscountSetting = BusinessSetting::where('business_id', $businessId)
//                 ->where('type', 'discount')->where('status', true)->first();
//             if ($globalDiscountSetting) {
//                 $appliedRules[] = 'Global Discount';
//                 if ($globalDiscountSetting->charge_type === 'percent') {
//                     $val = ($currentRunningTotal * (float)$globalDiscountSetting->value) / 100;
//                     $totalDiscount += $val;
//                     $currentRunningTotal -= $val;
//                 } else {
//                     $val = (float)$globalDiscountSetting->value;
//                     $totalDiscount += $val;
//                     $currentRunningTotal -= $val;
//                 }
//             }

//             // ---------------------------------------------------------
//             // B. FETCH ALL AUTO-APPLY RULES (DiscountRule)
//             // ---------------------------------------------------------
//             $rules = DiscountRule::where('business_id', $businessId)
//                 ->where('is_active', true)
//                 ->whereIn('applicable_for', ['pos', 'all'])
//                 ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                 ->orderBy('priority', 'asc')
//                 ->get();

//             // Sortir Prioritas: Rule yang direquest manual naik ke atas
//             if (!empty($requestedCodes)) {
//                 $rules = $rules->sortBy(function ($rule) use ($requestedCodes) {
//                     foreach ($requestedCodes as $code) {
//                         if (strcasecmp($rule->name, $code) === 0) return 0;
//                     }
//                     return 1;
//                 })->values();
//             }

//             // Validasi Kumulatif
//             $exclusiveRules = $rules->where('is_cumulative', false);
//             $requestedExclusive = $exclusiveRules->filter(function($r) use ($requestedCodes) {
//                  foreach ($requestedCodes as $c) { if (strcasecmp($r->name, $c) === 0) return true; }
//                  return false;
//             });

//             if ($requestedExclusive->count() > 0 && count($requestedCodes) > 1) {
//                 $names = $requestedExclusive->pluck('name')->implode(', ');
//                 throw new \Exception("Promo '$names' bersifat eksklusif dan tidak dapat digabung dengan promo lain.");
//             }
//             if ($requestedExclusive->count() > 1) {
//                  throw new \Exception("Tidak dapat menggabungkan beberapa promo eksklusif sekaligus.");
//             }

//             // ---------------------------------------------------------
//             // C. HITUNG DISKON RULES
//             // ---------------------------------------------------------
//             $productIds = Arr::pluck($items, 'product_id');
//             $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');

//             $processedRuleNames = [];

//             foreach ($rules as $rule) {
//                 // === UPDATE: CEK IGNORED RULES ===
//                 if (in_array($rule->name, $ignoredRules)) {
//                     Log::info("Skipping Ignored Rule: {$rule->name}");
//                     continue;
//                 }
//                 // =================================

//                 if ($stopFurtherProcessing) break;

//                 $discountAmountForRule = 0;
//                 $isManualRequest = false;
//                 foreach ($requestedCodes as $c) { if (strcasecmp($rule->name, $c) === 0) $isManualRequest = true; }

//                 if ($rule->type) {
//                     // A. POS Rules (Complex)
//                     $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
//                     if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                         $discountAmountForRule = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                     }
//                 } else {
//                     // B. Item Rules (Simple)
//                     $requiresSpecific = $rule->customer_channel || $rule->priority_level_id || $rule->customer_id;
//                     if ($requiresSpecific && !$member) continue;

//                     foreach ($items as $item) {
//                         $product = $productModels->get($item['product_id']);
//                         if (!$product) continue;

//                         $productMatch = !$rule->product_id || $rule->product_id == $product->id;
//                         $brandMatch = !$rule->brand_id || $rule->brand_id == $product->brand_id;
//                         if (!$productMatch || !$brandMatch) continue;

//                         $quantity = $item['quantity'];
//                         $uom = $item['uom'];

//                         $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                         $itemConv = $uomData?->conversion_rate ?? 1.0;
//                         $qtyInBase = $quantity * $itemConv;

//                         $ruleMinQty = $rule->min_quantity ?? 0;
//                         if ($rule->min_quantity_uom) {
//                              $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
//                              $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
//                         }

//                         if ($qtyInBase >= $ruleMinQty) {
//                             $basePrice = $product->price;

//                             if ($rule->discount_type === 'percentage') {
//                                 $discPerItem = ($basePrice * $rule->discount_value / 100) * $itemConv;
//                                 $discountAmountForRule += ($discPerItem * $quantity);
//                             } else {
//                                 // Bundle Logic
//                                 if ($ruleMinQty > 1) {
//                                     $bundles = floor($qtyInBase / $ruleMinQty);
//                                     $discountAmountForRule += ($bundles * $rule->discount_value);
//                                 } else {
//                                     $discountAmountForRule += ($rule->discount_value * $quantity);
//                                 }
//                             }
//                         }
//                     }
//                 }

//                 if ($discountAmountForRule > 0) {
//                     // Cek Max Discount
//                     if (isset($rule->max_discount) && $rule->max_discount > 0) {
//                         if ($discountAmountForRule > $rule->max_discount) {
//                             $discountAmountForRule = $rule->max_discount;
//                         }
//                     }

//                     // Cap dengan Running Total
//                     if ($discountAmountForRule > $currentRunningTotal) $discountAmountForRule = $currentRunningTotal;

//                     $totalDiscount += $discountAmountForRule;
//                     $currentRunningTotal -= $discountAmountForRule;

//                     $appliedRules[] = $rule->name;
//                     $processedRuleNames[] = strtoupper($rule->name);
//                     Log::info("Applied Rule: {$rule->name} ($discountAmountForRule)");

//                     if (!$rule->is_cumulative) {
//                         $stopFurtherProcessing = true;
//                         Log::info("Stopped by Exclusive Rule: {$rule->name}");
//                     }
//                 } else {
//                     if ($isManualRequest) {
//                         throw new \Exception("Syarat promo '{$rule->name}' tidak terpenuhi.");
//                     }
//                 }
//             }

//             // ---------------------------------------------------------
//             // D. PROSES SISA KODE (Voucher Member / Promo Umum)
//             // ---------------------------------------------------------
//             $remainingCodes = [];
//             foreach ($requestedCodes as $reqCode) {
//                 // Skip jika kode ini ada di ignoredRules
//                 if (in_array($reqCode, $ignoredRules)) continue;

//                 $isProcessed = false;
//                 foreach ($processedRuleNames as $procName) {
//                     if (strcasecmp($reqCode, $procName) === 0) $isProcessed = true;
//                 }
//                 if (!$isProcessed) $remainingCodes[] = $reqCode;
//             }

//             foreach ($remainingCodes as $code) {
//                 if ($stopFurtherProcessing) break;

//                 $codeApplied = false;
//                 $discountAmountForCode = 0;

//                 // 1. Cek Promo Umum
//                 $promo = Promo::whereRaw('LOWER(code) = ?', [strtolower($code)])
//                     ->where('business_id', $businessId)
//                     ->where(fn($q) => $q->whereNull('activated_at')->orWhere('activated_at', '<=', $now))
//                     ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>=', $now))
//                     ->first();

//                 // 2. Cek Member Voucher
//                 $memberVoucher = null;
//                 if (!$promo) {
//                     $memberVoucher = MemberVoucher::where('code', $code)
//                         ->where('is_used', false)
//                         ->with('discountRule')
//                         ->first();

//                     if ($memberVoucher) {
//                          if (!$member || (is_object($member) && $member->id !== $memberVoucher->member_id)) {
//                              Log::warning("Voucher Owner Mismatch: $code");
//                              $memberVoucher = null;
//                          }
//                     }
//                 }

//                 if ($memberVoucher && $memberVoucher->discountRule) {
//                     $rule = $memberVoucher->discountRule;

//                     if (!$rule->is_cumulative && count($appliedRules) > 0) {
//                          throw new \Exception("Voucher '{$code}' tidak dapat digabung dengan promo lain.");
//                     }

//                     $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);

//                     if ($rule->type) {
//                         if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                             $discountAmountForCode = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                         }
//                     } else {
//                         foreach ($items as $item) {
//                             $product = $productModels->get($item['product_id']);
//                             if (!$product) continue;
//                             if ($rule->product_id && $rule->product_id != $product->id) continue;
//                             if ($rule->brand_id && $rule->brand_id != $product->brand_id) continue;

//                             $quantity = $item['quantity'];
//                             $uom = $item['uom'];
//                             $basePrice = $product->price;

//                             $ruleMinQty = $rule->min_quantity ?? 0;
//                             if ($ruleMinQty > 0) {
//                                 $itemUomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                 $itemConv = $itemUomData?->conversion_rate ?? 1;
//                                 $qtyInBase = $quantity * $itemConv;

//                                 if ($rule->min_quantity_uom) {
//                                      $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
//                                      $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
//                                 }
//                                 if ($qtyInBase < $ruleMinQty) continue;
//                             }

//                             $val = 0;
//                             if ($rule->discount_type === 'percentage') {
//                                 $val = ((float)$basePrice * (float)$rule->discount_value / 100);
//                             } else {
//                                 if ($ruleMinQty > 1) {
//                                     $bundles = floor($qtyInBase / $ruleMinQty);
//                                     $val = (float)$rule->discount_value / $ruleMinQty;
//                                 } else {
//                                     $val = (float)$rule->discount_value;
//                                 }
//                             }

//                             $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                             $conv = $uomData?->conversion_rate ?? 1;
//                             $discountAmountForCode += ($val * $conv * $quantity);
//                         }
//                     }

//                     if ($discountAmountForCode > 0) {
//                         $codeApplied = true;
//                         $appliedRules[] = "Voucher: {$memberVoucher->code}";
//                     }
//                 }
//                 elseif ($promo) {
//                     $isEligible = $subTotal >= ($promo->min_purchase ?? 0);
//                     if ($isEligible) {
//                         $val = $promo->discount_amount ?? $promo->discount_value ?? 0;
//                         $discountAmountForCode = (float)$val;
//                         $codeApplied = true;
//                         $appliedRules[] = "Promo: {$promo->code}";
//                     }
//                 }

//                 if ($codeApplied) {
//                     if ($discountAmountForCode > $currentRunningTotal) $discountAmountForCode = $currentRunningTotal;
//                     $totalDiscount += $discountAmountForCode;
//                     $currentRunningTotal -= $discountAmountForCode;
//                     Log::info("Manual Promo Applied: $code ($discountAmountForCode)");
//                 } else {
//                     throw new \Exception("Syarat promo/voucher '$code' tidak terpenuhi atau tidak valid.");
//                 }
//             } if (!empty($remainingCodes)) {
//                 throw new \Exception("Promo tambahan tidak dapat digabung karena ada diskon eksklusif.");
//             }

//             // Final check
//             if ($totalDiscount > $subTotal) $totalDiscount = $subTotal;

//             Log::info("FINAL DISCOUNT: $totalDiscount");
//             return [
//                 'total_discount' => $totalDiscount,
//                 'applied_rules' => array_unique($appliedRules),
//                 // Tambahan field untuk redeem poin jika diperlukan oleh controller di masa depan,
//                 // saat ini diluar scope fungsi calculate ini tapi baik untuk konsistensi return array.
//                 'points_redeemed' => 0,
//                 'point_value' => 0,
//             ];

//         } catch (\Throwable $e) {
//             Log::error("POS CALC ERROR: " . $e->getMessage());
//             throw $e;
//         }
//     }

//     // --- HELPER POS ---
//     private static function isPosRuleApplicable(DiscountRule $rule, array $items, float $subTotal): bool {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
//         if (!$condition) return false;
//         switch ($rule->type) {
//             case 'minimum_purchase':
//                 return $subTotal >= (float)($condition['amount'] ?? 0);
//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $reqQty = ($condition['buy_quantity'] ?? 1) + ($condition['get_quantity'] ?? 1);
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
//                 return $item && $item['quantity'] >= $reqQty;
//             case 'category_discount':
//                 $catId = $condition['category_id'] ?? null;
//                 if(!$catId) return false;
//                 $pIds = Arr::pluck($items, 'product_id');
//                 return Product::whereIn('id', $pIds)->where('category_id', $catId)->exists();
//             case 'buy_x_get_y':
//                 $buyId = $condition['buy_product_id'] ?? null;
//                 $getId = $condition['get_product_id'] ?? null;
//                 $hasBuy = Arr::first($items, fn ($i) => $i['product_id'] == $buyId);
//                 $hasGet = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
//                 return $hasBuy && $hasGet;
//             default: return false;
//         }
//     }

//     private static function calculatePosRuleDiscount(DiscountRule $rule, array $items, float $subTotal): float {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
//         switch ($rule->type) {
//             case 'minimum_purchase':
//                 $thresholdAmount = (float)($condition['amount'] ?? 0);
//                 if ($rule->discount_type === 'percentage') {
//                     $calcFromTotal = isset($condition['calculate_from_total']) && $condition['calculate_from_total'] === true;
//                     if ($calcFromTotal || $thresholdAmount <= 0) {
//                         return ($subTotal * (float)$rule->discount_value) / 100;
//                     } else {
//                         return ($thresholdAmount * (float)$rule->discount_value) / 100;
//                     }
//                 }
//                 return (float)$rule->discount_value;

//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $buyQty = (int)($condition['buy_quantity'] ?? 1);
//                 $getQty = (int)($condition['get_quantity'] ?? 1);
//                 $totalOfferUnit = $buyQty + $getQty;
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
//                 if (!$item) return 0;
//                 $offers = floor($item['quantity'] / $totalOfferUnit);
//                 return $offers * $getQty * (float)($item['price'] ?? 0);

//             case 'category_discount':
//                 $categoryId = $condition['category_id'] ?? null;
//                 if (!$categoryId) return 0;
//                 $pIds = Product::where('category_id', $categoryId)->pluck('id')->toArray();
//                 $totalForCat = collect($items)->whereIn('product_id', $pIds)->sum('total');
//                 if ($rule->discount_type === 'percentage') {
//                     return ($totalForCat * (float)$rule->discount_value) / 100;
//                 }
//                 return (float)$rule->discount_value;

//             case 'buy_x_get_y':
//                 $getId = $condition['get_product_id'] ?? null;
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
//                 if (!$item) return 0;
//                 return ($rule->discount_type === 'percentage') ? ((float)($item['price'] ?? 0) * (float)$rule->discount_value) / 100 : (float)$rule->discount_value;

//             default: return 0;
//         }
//     }
// }

// class PosDiscountService
// {
//     /**
//      * Helper: Decode JSON aman
//      */
//     private static function safeJsonDecode($value)
//     {
//         if (is_array($value)) return $value;
//         if (empty($value)) return [];
//         $decoded = json_decode($value, true);
//         if (json_last_error() !== JSON_ERROR_NONE || is_string($decoded)) {
//             $clean = trim($value, '"');
//             $clean = str_replace('""', '"', $clean);
//             $decoded = json_decode($clean, true);
//         }
//         return is_array($decoded) ? $decoded : [];
//     }

//     private static function generateDescription($rule)
//     {
//         if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
//         if ($rule->type == 'minimum_purchase') return "Min. Belanja";
//         if ($rule->type == 'category_discount') return "Diskon Kategori";
//         if ($rule->type == 'buy_x_get_y') return "Bundling";

//         if ($rule->discount_type == 'percentage') return "Diskon " . (float)$rule->discount_value . "%";
//         return "Potongan Harga";
//     }

//     /**
//      * UI Helper: Get Promos for Slider (Katalog Promo)
//      */
//     public static function getApplicablePromosForProduct(Product $product, Outlet $outlet): array
//     {
//         $productId = $product->id;
//         $productBrandId = $product->brand_id;
//         $categoryId = $product->category_id;
//         $now = Carbon::now();

//         try {
//             $rules = DiscountRule::where('business_id', $product->business_id)
//                 ->where('is_active', true)
//                 ->whereIn('applicable_for', ['pos', 'all'])
//                 ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                 ->orderBy('priority', 'asc')
//                 ->get();

//             $matchedRules = [];

//             foreach ($rules as $rule) {
//                 $isMatch = false;
//                 $condition = self::safeJsonDecode($rule->condition_value);

//                 if ($rule->type) {
//                     switch ($rule->type) {
//                         case 'bogo_same_item': if (($condition['product_id']??null) == $productId) $isMatch = true; break;
//                         case 'category_discount': if (($condition['category_id']??null) == $categoryId) $isMatch = true; break;
//                         case 'buy_x_get_y': if (($condition['buy_product_id']??null) == $productId || ($condition['get_product_id']??null) == $productId) $isMatch = true; break;
//                         case 'minimum_purchase': $isMatch = true; break;
//                     }
//                 } else {
//                     if ($rule->product_id == $productId) $isMatch = true;
//                     elseif ($rule->brand_id && $rule->brand_id == $productBrandId) $isMatch = true;
//                 }

//                 if ($isMatch) {
//                     $matchedRules[] = [
//                         'id' => $rule->id,
//                         'name' => $rule->name,
//                         'discount_type' => $rule->discount_type,
//                         'discount_value' => (string)$rule->discount_value,
//                         'type' => $rule->type,
//                         'description' => self::generateDescription($rule),
//                         'condition_value' => $condition,
//                         'is_cumulative' => (bool)$rule->is_cumulative,
//                     ];
//                 }
//             }
//             return $matchedRules;
//         } catch (\Exception $e) {
//             return [];
//         }
//     }

//     /**
//      * Main Calculation Logic for POS
//      * MODE: AUTO-APPLY (DiscountRule) + MANUAL (Promo/Voucher)
//      */
//     public static function calculate(
//         array $items,
//         float $subTotal,
//         int $businessId,
//         ?string $promoCodeInput,
//         $member,
//         bool $usePoints = false // <-- PARAMETER BARU
//     ): array
//     {
//         try {
//             $now = Carbon::now();
//             $memberId = is_object($member) ? ($member->id ?? 'Unknown') : 'Guest';

//             // 1. Parsing Kode Promo
//             $requestedCodes = [];
//             if (!empty($promoCodeInput)) {
//                 $rawCodes = explode(',', $promoCodeInput);
//                 $requestedCodes = array_unique(array_filter(array_map('trim', $rawCodes)));
//             }

//             Log::info("POS CALC START | User: $memberId | Total: $subTotal | Points: " . ($usePoints ? 'YES' : 'NO'));

//             $totalDiscount = 0;
//             $appliedRules = [];
//             $stopFurtherProcessing = false;

//             // Logic Running Total
//             $currentRunningTotal = $subTotal;

//             // ---------------------------------------------------------
//             // A. GLOBAL DISCOUNT
//             // ---------------------------------------------------------
//             $globalDiscountSetting = BusinessSetting::where('business_id', $businessId)
//                 ->where('type', 'discount')->where('status', true)->first();
//             if ($globalDiscountSetting) {
//                 $appliedRules[] = 'Global Discount';
//                 if ($globalDiscountSetting->charge_type === 'percent') {
//                     $val = ($currentRunningTotal * (float)$globalDiscountSetting->value) / 100;
//                     $totalDiscount += $val;
//                     $currentRunningTotal -= $val;
//                 } else {
//                     $val = (float)$globalDiscountSetting->value;
//                     $totalDiscount += $val;
//                     $currentRunningTotal -= $val;
//                 }
//             }

//             // ---------------------------------------------------------
//             // B. FETCH RULES
//             // ---------------------------------------------------------
//             $rules = DiscountRule::where('business_id', $businessId)
//                 ->where('is_active', true)
//                 ->whereIn('applicable_for', ['pos', 'all'])
//                 ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                 ->orderBy('priority', 'asc')
//                 ->get();

//             if (!empty($requestedCodes)) {
//                 $rules = $rules->sortBy(function ($rule) use ($requestedCodes) {
//                     foreach ($requestedCodes as $code) {
//                         if (strcasecmp($rule->name, $code) === 0) return 0;
//                     }
//                     return 1;
//                 })->values();
//             }

//             $exclusiveRules = $rules->where('is_cumulative', false);
//             $requestedExclusive = $exclusiveRules->filter(function($r) use ($requestedCodes) {
//                  foreach ($requestedCodes as $c) { if (strcasecmp($r->name, $c) === 0) return true; }
//                  return false;
//             });

//             if ($requestedExclusive->count() > 0 && count($requestedCodes) > 1) {
//                 $names = $requestedExclusive->pluck('name')->implode(', ');
//                 throw new \Exception("Promo '$names' bersifat eksklusif dan tidak dapat digabung.");
//             }
//             if ($requestedExclusive->count() > 1) {
//                  throw new \Exception("Tidak dapat menggabungkan beberapa promo eksklusif.");
//             }

//             // ---------------------------------------------------------
//             // C. HITUNG DISKON RULES
//             // ---------------------------------------------------------
//             $productIds = Arr::pluck($items, 'product_id');
//             $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');
//             $processedRuleNames = [];

//             foreach ($rules as $rule) {
//                 if ($stopFurtherProcessing) break;
//                 $discountAmountForRule = 0;
//                 $isManualRequest = false;
//                 foreach ($requestedCodes as $c) { if (strcasecmp($rule->name, $c) === 0) $isManualRequest = true; }

//                 if ($rule->type) {
//                     $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
//                     if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                         $discountAmountForRule = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                     }
//                 } else {
//                     $requiresSpecific = $rule->customer_channel || $rule->priority_level_id || $rule->customer_id;
//                     if ($requiresSpecific && !$member) continue;

//                     foreach ($items as $item) {
//                         $product = $productModels->get($item['product_id']);
//                         if (!$product) continue;
//                         $productMatch = !$rule->product_id || $rule->product_id == $product->id;
//                         $brandMatch = !$rule->brand_id || $rule->brand_id == $product->brand_id;
//                         if (!$productMatch || !$brandMatch) continue;

//                         $quantity = $item['quantity'];
//                         $uom = $item['uom'];
//                         $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                         $itemConv = $uomData?->conversion_rate ?? 1.0;
//                         $qtyInBase = $quantity * $itemConv;
//                         $ruleMinQty = $rule->min_quantity ?? 0;
//                         if ($rule->min_quantity_uom) {
//                              $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
//                              $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
//                         }
//                         if ($qtyInBase >= $ruleMinQty) {
//                             $basePrice = $product->price;
//                             if ($rule->discount_type === 'percentage') {
//                                 $discPerItem = ($basePrice * $rule->discount_value / 100) * $itemConv;
//                                 $discountAmountForRule += ($discPerItem * $quantity);
//                             } else {
//                                 if ($ruleMinQty > 1) {
//                                     $bundles = floor($qtyInBase / $ruleMinQty);
//                                     $discountAmountForRule += ($bundles * $rule->discount_value);
//                                 } else {
//                                     $discountAmountForRule += ($rule->discount_value * $quantity);
//                                 }
//                             }
//                         }
//                     }
//                 }

//                 if ($discountAmountForRule > 0) {
//                     if (isset($rule->max_discount) && $rule->max_discount > 0) {
//                         if ($discountAmountForRule > $rule->max_discount) $discountAmountForRule = $rule->max_discount;
//                     }
//                     if ($discountAmountForRule > $currentRunningTotal) $discountAmountForRule = $currentRunningTotal;

//                     $totalDiscount += $discountAmountForRule;
//                     $currentRunningTotal -= $discountAmountForRule;

//                     $appliedRules[] = $rule->name;
//                     $processedRuleNames[] = strtoupper($rule->name);

//                     if (!$rule->is_cumulative) {
//                         $stopFurtherProcessing = true;
//                     }
//                 } else {
//                     if ($isManualRequest) throw new \Exception("Syarat promo '{$rule->name}' tidak terpenuhi.");
//                 }
//             }

//             // ---------------------------------------------------------
//             // D. PROSES KODE MANUAL
//             // ---------------------------------------------------------
//             $remainingCodes = [];
//             foreach ($requestedCodes as $reqCode) {
//                 $isProcessed = false;
//                 foreach ($processedRuleNames as $procName) {
//                     if (strcasecmp($reqCode, $procName) === 0) $isProcessed = true;
//                 }
//                 if (!$isProcessed) $remainingCodes[] = $reqCode;
//             }

//             if (!$stopFurtherProcessing) {
//                 foreach ($remainingCodes as $code) {
//                     $codeApplied = false;
//                     $discountAmountForCode = 0;

//                     $promo = Promo::whereRaw('LOWER(code) = ?', [strtolower($code)])
//                         ->where('business_id', $businessId)
//                         ->where(fn($q) => $q->whereNull('activated_at')->orWhere('activated_at', '<=', $now))
//                         ->where(fn($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>=', $now))
//                         ->first();

//                     $memberVoucher = null;
//                     if (!$promo) {
//                         $memberVoucher = MemberVoucher::where('code', $code)
//                             ->where('is_used', false)
//                             ->with('discountRule')
//                             ->first();
//                         if ($memberVoucher) {
//                              if (!$member || (is_object($member) && $member->id !== $memberVoucher->member_id)) {
//                                  $memberVoucher = null;
//                              }
//                         }
//                     }

//                     if ($memberVoucher && $memberVoucher->discountRule) {
//                         $rule = $memberVoucher->discountRule;
//                         if (!$rule->is_cumulative && count($appliedRules) > 0) {
//                              throw new \Exception("Voucher '{$code}' tidak dapat digabung dengan promo lain.");
//                         }
//                         $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);

//                         if ($rule->type) {
//                             if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                                 $discountAmountForCode = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                             }
//                         } else {
//                             foreach ($items as $item) {
//                                 $product = $productModels->get($item['product_id']);
//                                 if (!$product) continue;
//                                 if ($rule->product_id && $rule->product_id != $product->id) continue;
//                                 if ($rule->brand_id && $rule->brand_id != $product->brand_id) continue;

//                                 $quantity = $item['quantity'];
//                                 $uom = $item['uom'];
//                                 $basePrice = $product->price;

//                                 $ruleMinQty = $rule->min_quantity ?? 0;
//                                 if ($ruleMinQty > 0) {
//                                     $itemUomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                     $itemConv = $itemUomData?->conversion_rate ?? 1;
//                                     $qtyInBase = $quantity * $itemConv;
//                                     if ($rule->min_quantity_uom) {
//                                          $rUom = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $rule->min_quantity_uom) === 0);
//                                          $ruleMinQty *= ($rUom?->conversion_rate ?? 1);
//                                     }
//                                     if ($qtyInBase < $ruleMinQty) continue;
//                                 }

//                                 $val = 0;
//                                 if ($rule->discount_type === 'percentage') {
//                                     $val = ((float)$basePrice * (float)$rule->discount_value / 100);
//                                 } else {
//                                     if ($ruleMinQty > 1) {
//                                         $val = (float)$rule->discount_value / $ruleMinQty;
//                                     } else {
//                                         $val = (float)$rule->discount_value;
//                                     }
//                                 }

//                                 $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                 $conv = $uomData?->conversion_rate ?? 1;
//                                 $discountAmountForCode += ($val * $conv * $quantity);
//                             }
//                         }

//                         if ($discountAmountForCode > 0) {
//                             $codeApplied = true;
//                             $appliedRules[] = "Voucher: {$memberVoucher->code}";
//                         }
//                     }
//                     elseif ($promo) {
//                         $isEligible = $subTotal >= ($promo->min_purchase ?? 0);
//                         if ($isEligible) {
//                             $val = $promo->discount_amount ?? $promo->discount_value ?? 0;
//                             $discountAmountForCode = (float)$val;
//                             $codeApplied = true;
//                             $appliedRules[] = "Promo: {$promo->code}";
//                         }
//                     }

//                     if ($codeApplied) {
//                         if ($discountAmountForCode > $currentRunningTotal) $discountAmountForCode = $currentRunningTotal;
//                         $totalDiscount += $discountAmountForCode;
//                         $currentRunningTotal -= $discountAmountForCode;
//                         Log::info("Manual Promo Applied: $code ($discountAmountForCode)");
//                     } else {
//                         throw new \Exception("Syarat promo/voucher '$code' tidak terpenuhi atau tidak valid.");
//                     }
//                 }
//             } elseif (!empty($remainingCodes)) {
//                  throw new \Exception("Promo tambahan tidak dapat digabung karena ada diskon eksklusif.");
//             }

//             // ---------------------------------------------------------
//             // E. REDEEM POINTS (FITUR BARU)
//             // ---------------------------------------------------------
//             $redeemablePoints = 0;
//             $pointsValue = 0;

//             // Hanya jika user meminta pakai poin & member valid & sisa tagihan masih ada
//             if ($usePoints && $member && is_object($member) && $currentRunningTotal > 0) {

//                 $currentPoints = (float)$member->current_points;

//                 if ($currentPoints > 0) {
//                     // 1. Ambil Rate (1 Poin = Rp X)
//                     $exchangeRate = 1; // Default
//                     $rateSetting = BusinessSetting::where('business_id', $businessId)
//                         ->where('type', 'point_exchange_rate')->first();
//                     if ($rateSetting) $exchangeRate = (float)$rateSetting->value;

//                     // 2. Hitung nilai maksimal poin member dalam Rupiah
//                     $maxMemberValue = $currentPoints * $exchangeRate;

//                     // 3. Tentukan berapa yang bisa dipakai (Tidak boleh > Sisa Tagihan)
//                     $redeemValue = min($maxMemberValue, $currentRunningTotal);

//                     // 4. Konversi balik ke jumlah poin (pembulatan ke atas agar tidak rugi toko)
//                     $redeemablePoints = ceil($redeemValue / $exchangeRate);

//                     // Hitung ulang nilai Rupiah persis dari poin yang akan dipotong
//                     $pointsValue = $redeemablePoints * $exchangeRate;

//                     // Safety: Jangan sampai value melebihi tagihan (rounding issue)
//                     if ($pointsValue > $currentRunningTotal) $pointsValue = $currentRunningTotal;

//                     $totalDiscount += $pointsValue;
//                     $currentRunningTotal -= $pointsValue;

//                     $appliedRules[] = "Redeem: $redeemablePoints Poin (Rp " . number_format($pointsValue,0) . ")";

//                     Log::info("Points Redeemed: $redeemablePoints pts = Rp $pointsValue");
//                 }
//             }

//             // Final check
//             if ($totalDiscount > $subTotal) $totalDiscount = $subTotal;

//             return [
//                 'total_discount' => $totalDiscount,
//                 'applied_rules' => array_unique($appliedRules),
//                 'points_redeemed' => $redeemablePoints ?? 0, // Kembalikan info poin
//                 'point_value' => $pointsValue ?? 0,
//             ];

//         } catch (\Throwable $e) {
//             Log::error("POS CALC ERROR: " . $e->getMessage());
//             throw $e;
//         }
//     }

//     // --- HELPER POS ---
//     private static function isPosRuleApplicable(DiscountRule $rule, array $items, float $subTotal): bool {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
//         if (!$condition) return false;
//         switch ($rule->type) {
//             case 'minimum_purchase':
//                 return $subTotal >= (float)($condition['amount'] ?? 0);
//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $reqQty = ($condition['buy_quantity'] ?? 1) + ($condition['get_quantity'] ?? 1);
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
//                 return $item && $item['quantity'] >= $reqQty;
//             case 'category_discount':
//                 $catId = $condition['category_id'] ?? null;
//                 if(!$catId) return false;
//                 $pIds = Arr::pluck($items, 'product_id');
//                 return Product::whereIn('id', $pIds)->where('category_id', $catId)->exists();
//             case 'buy_x_get_y':
//                 $buyId = $condition['buy_product_id'] ?? null;
//                 $getId = $condition['get_product_id'] ?? null;
//                 $hasBuy = Arr::first($items, fn ($i) => $i['product_id'] == $buyId);
//                 $hasGet = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
//                 return $hasBuy && $hasGet;
//             default: return false;
//         }
//     }

//     private static function calculatePosRuleDiscount(DiscountRule $rule, array $items, float $subTotal): float {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);
//         switch ($rule->type) {
//             case 'minimum_purchase':
//                 // THRESHOLD LOGIC FIX
//                 $thresholdAmount = (float)($condition['amount'] ?? 0);
//                 if ($rule->discount_type === 'percentage') {
//                     $calcFromTotal = isset($condition['calculate_from_total']) && $condition['calculate_from_total'] === true;
//                     if ($calcFromTotal || $thresholdAmount <= 0) {
//                         return ($subTotal * (float)$rule->discount_value) / 100;
//                     } else {
//                         return ($thresholdAmount * (float)$rule->discount_value) / 100;
//                     }
//                 }
//                 return (float)$rule->discount_value;

//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $buyQty = (int)($condition['buy_quantity'] ?? 1);
//                 $getQty = (int)($condition['get_quantity'] ?? 1);
//                 $totalOfferUnit = $buyQty + $getQty;
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $productId);
//                 if (!$item) return 0;
//                 $offers = floor($item['quantity'] / $totalOfferUnit);
//                 return $offers * $getQty * (float)($item['price'] ?? 0);

//             case 'category_discount':
//                 $categoryId = $condition['category_id'] ?? null;
//                 if (!$categoryId) return 0;
//                 $pIds = Product::where('category_id', $categoryId)->pluck('id')->toArray();
//                 $totalForCat = collect($items)->whereIn('product_id', $pIds)->sum('total');
//                 if ($rule->discount_type === 'percentage') {
//                     return ($totalForCat * (float)$rule->discount_value) / 100;
//                 }
//                 return (float)$rule->discount_value;

//             case 'buy_x_get_y':
//                 $getId = $condition['get_product_id'] ?? null;
//                 $item = Arr::first($items, fn ($i) => $i['product_id'] == $getId);
//                 if (!$item) return 0;
//                 return ($rule->discount_type === 'percentage') ? ((float)($item['price'] ?? 0) * (float)$rule->discount_value) / 100 : (float)$rule->discount_value;

//             default: return 0;
//         }
//     }
// }
