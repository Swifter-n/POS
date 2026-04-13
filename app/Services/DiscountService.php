<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\MemberVoucher;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

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
     * MODE: HYBRID (Auto Apply + Manual Trigger) + IGNORED RULES
     */
    public static function calculate(
        array $items,
        float $subTotal,
        int $businessId,
        ?string $promoCodeInput,
        $member,
        bool $usePoints = false,
        array $ignoredRules = [] // <-- Parameter Ignored Rules
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

            // === PERBAIKAN UTAMA: NORMALISASI IGNORED RULES ===
            // Ubah semua ke lowercase dan trim untuk pencarian yang akurat
            $normalizedIgnoredRules = array_map(fn($r) => strtolower(trim($r)), $ignoredRules);
            // =================================================

            Log::info("POS CALC START | User: $memberId | Total: $subTotal | Codes: " . json_encode(array_values($requestedCodes)) . " | Ignored: " . json_encode($normalizedIgnoredRules));

            $totalDiscount = 0;
            $appliedRules = [];
            $stopFurtherProcessing = false;

            // Logic Running Total (untuk persen)
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
            // B. FETCH ALL AUTO-APPLY RULES (DiscountRule)
            // ---------------------------------------------------------
            $rules = DiscountRule::where('business_id', $businessId)
                ->where('is_active', true)
                ->whereIn('applicable_for', ['pos', 'all'])
                ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
                ->orderBy('priority', 'asc')
                ->get();

            // Filter berdasarkan kepemilikan voucher member (untuk keamanan)
            if (is_object($member)) {
                $activeVoucherRuleIds = MemberVoucher::where('member_id', $member->id)
                    ->where('is_used', false)
                    ->where(fn($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                    ->pluck('discount_rule_id')
                    ->toArray();

                $rules = $rules->filter(function($rule) use ($activeVoucherRuleIds, $member) {
                    // 1. Jika Rule ada di daftar voucher aktif member -> LOLOS
                    if (in_array($rule->id, $activeVoucherRuleIds)) return true;

                    // 2. Jika Rule sudah pernah dipakai (is_used=true) -> BLOKIR
                    $hasUsedVoucher = MemberVoucher::where('member_id', $member->id)
                        ->where('discount_rule_id', $rule->id)
                        ->where('is_used', true)
                        ->exists();
                    if ($hasUsedVoucher) return false;

                    // 3. Rule Umum -> LOLOS
                    return true;
                });
            }

            // Jika ada kode manual, naikkan prioritasnya
            if (!empty($requestedCodes)) {
                $rules = $rules->sortBy(function ($rule) use ($requestedCodes) {
                    foreach ($requestedCodes as $code) {
                        if (strcasecmp($rule->name, $code) === 0) return 0;
                    }
                    return 1;
                })->values();
            }

            // Validasi Kumulatif
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

            // ---------------------------------------------------------
            // C. HITUNG DISKON RULES
            // ---------------------------------------------------------
            $productIds = Arr::pluck($items, 'product_id');
            $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');

            $processedRuleNames = [];

            foreach ($rules as $rule) {
                // === CHECK IGNORED RULES (RESTORED) ===
                // Cek apakah nama rule ini ada dalam daftar ignore (case insensitive)
                if (in_array(strtolower(trim($rule->name)), $normalizedIgnoredRules)) {
                    Log::info("Skipping Ignored Rule: {$rule->name}");
                    continue;
                }
                // ======================================

                if ($stopFurtherProcessing) break;

                $discountAmountForRule = 0;
                $isManualRequest = false;
                foreach ($requestedCodes as $c) { if (strcasecmp($rule->name, $c) === 0) $isManualRequest = true; }

                if ($rule->type) {
                    // A. POS Rules (Complex)
                    $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
                    if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
                        $discountAmountForRule = self::calculatePosRuleDiscount($rule, $items, $subTotal);
                    }
                } else {
                    // B. Item Rules (Simple)
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
                        Log::info("Stopped by Exclusive Rule: {$rule->name}");
                    }
                } else {
                    if ($isManualRequest) throw new \Exception("Syarat promo '{$rule->name}' tidak terpenuhi.");
                }
            }

            // ---------------------------------------------------------
            // D. PROSES SISA KODE (Voucher Member / Promo Umum)
            // ---------------------------------------------------------
            $remainingCodes = [];
            foreach ($requestedCodes as $reqCode) {
                // Skip jika kode manual ini juga di-ignore (meskipun jarang terjadi di UI)
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
                        ->with('discountRule')
                        ->first();
                    if ($memberVoucher) {
                         if (!$member || (is_object($member) && $member->id !== $memberVoucher->member_id)) {
                             Log::warning("Voucher Owner Mismatch: $code");
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
                                    $bundles = floor($qtyInBase / $ruleMinQty);
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

// class DiscountService
// {
//     private static function safeJsonDecode($value)
//     {
//         if (is_array($value)) return $value;
//         if (empty($value)) return [];
//         $decoded = json_decode($value, true);
//         if (json_last_error() !== JSON_ERROR_NONE || is_string($decoded)) {
//             $cleanValue = trim($value, '"');
//             $cleanValue = str_replace('""', '"', $cleanValue);
//             $decoded = json_decode($cleanValue, true);
//         }
//         return is_array($decoded) ? $decoded : [];
//     }

//     /**
//      * UI Helper: Menampilkan daftar promo yang TERSEDIA (bukan yang diterapkan).
//      * Di sini kita tetap mengambil SEMUA promo aktif agar kasir bisa melihat "katalog promo".
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
//                 ->where(fn($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                 ->where(fn($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
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
//                         'name' => $rule->name, // Ini akan jadi Kode Promo saat di-tap
//                         'discount_type' => $rule->discount_type,
//                         'discount_value' => (string)$rule->discount_value,
//                         'type' => $rule->type,
//                         'description' => self::generateDescription($rule),
//                         'condition_value' => $condition,
//                     ];
//                 }
//             }
//             return $matchedRules;
//         } catch (\Exception $e) {
//             Log::error("Gagal mengambil promo: " . $e->getMessage());
//             return [];
//         }
//     }

//     private static function generateDescription($rule)
//     {
//         if ($rule->type == 'bogo_same_item') return "Beli 1 Gratis 1";
//         if ($rule->type == 'minimum_purchase') return "Min. Belanja";
//         if ($rule->discount_type == 'percentage') return "Diskon {$rule->discount_value}%";
//         return "Potongan Harga";
//     }

//     /**
//      * Menghitung total diskon.
//      * PERUBAHAN: HANYA menghitung rule yang NAMA-nya cocok dengan $promoCode.
//      * Otomatisasi dimatikan sesuai request.
//      */
//     public static function calculate(
//         array $items,
//         float $subTotal,
//         int $businessId,
//         ?string $promoCode,
//         $customer,
//         string $channel = 'pos'
//     ): array
//     {
//         $now = Carbon::now();
//         // Bersihkan spasi kode promo
//         $promoCode = $promoCode ? trim($promoCode) : null;

//         try {
//             $memberId = is_object($customer) ? ($customer->id ?? 'Unknown') : 'Guest';
//             Log::info("CALC START | Code: " . ($promoCode ?? 'None') . " | User: $memberId | SubTotal: $subTotal");

//             $totalDiscount = 0;
//             $appliedRules = [];

//             $pricingService = new PricingService();

//             // 1. Global Discount (Tetap Otomatis - Karena setting bisnis)
//             $globalDiscountSetting = BusinessSetting::where('business_id', $businessId)
//                 ->where('type', 'discount')->where('status', true)->first();
//             if ($globalDiscountSetting) {
//                 $appliedRules[] = 'Global Business Discount';
//                 if ($globalDiscountSetting->charge_type === 'percent') {
//                     $totalDiscount += ($subTotal * (float)$globalDiscountSetting->value) / 100;
//                 } else {
//                     $totalDiscount += (float)$globalDiscountSetting->value;
//                 }
//             }

//             // 2. Discount Rules (STRICT MANUAL TRIGGER)
//             // Kita hanya mengambil rule jika namanya SAMA dengan kode promo yang diinput
//             if (!empty($promoCode)) {
//                 $rules = DiscountRule::where('business_id', $businessId)
//                     ->where('is_active', true)
//                     ->whereIn('applicable_for', [$channel, 'all'])
//                     // === FILTER UTAMA: HANYA AMBIL YANG NAMANYA COCOK ===
//                     // Gunakan ILIKE di Postgres atau LIKE di MySQL untuk case-insensitive,
//                     // atau filter manual di collection PHP untuk kompatibilitas.
//                     // Di sini kita ambil dulu yang mungkin cocok.
//                     ->where(fn($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
//                     ->where(fn($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now))
//                     ->get();

//                 // Filter strict by Name (Case Insensitive) di PHP
//                 $targetRule = $rules->first(function($rule) use ($promoCode) {
//                     return strcasecmp($rule->name, $promoCode) === 0;
//                 });

//                 // Jika ketemu Rule yang namanya == Kode Promo
//                 if ($targetRule) {
//                     Log::info(">> Rule Matched by Code: {$targetRule->name}");

//                     // Lakukan perhitungan untuk Rule ini saja
//                     $discountAmountForRule = 0;
//                     $rule = $targetRule;

//                     $productIds = Arr::pluck($items, 'product_id');
//                     $productModels = Product::with('uoms')->whereIn('id', $productIds)->get()->keyBy('id');

//                     if ($rule->type) {
//                         // Logic POS (Complex)
//                         $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
//                         if (self::isPosRuleApplicable($rule, $items, $subTotal)) {
//                             $discountAmountForRule = self::calculatePosRuleDiscount($rule, $items, $subTotal);
//                         }
//                     } else {
//                         // Logic Item (Simple)
//                         // Validasi Customer Requirement untuk B2B/POS
//                         $allowRule = true;
//                         $requiresSpecificCustomer = $rule->customer_channel || $rule->priority_level_id || $rule->customer_id;
//                         if ($requiresSpecificCustomer && $channel === 'pos' && !$customer) {
//                              $allowRule = false; // Butuh member tapi guest
//                         }

//                         if ($allowRule) {
//                             foreach ($items as $item) {
//                                 $product = $productModels->get($item['product_id']);
//                                 if (!$product) continue;

//                                 // Validasi Produk/Brand
//                                 if ($rule->product_id && $rule->product_id != $product->id) continue;
//                                 if ($rule->brand_id && $rule->brand_id != $product->brand_id) continue;

//                                 $quantity = $item['quantity'];
//                                 $uom = $item['uom'];
//                                 $isApplicable = false;

//                                 if ($customer && $channel === 'sales_order') {
//                                     $isApplicable = $pricingService->isRuleApplicable($rule, $customer, $product, $quantity, $uom);
//                                 } else {
//                                     // Cek Min Qty
//                                     $qtyMatch = true;
//                                     if ($rule->min_quantity) {
//                                         $itemUomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                         $itemConv = $itemUomData?->conversion_rate ?? 1;
//                                         $itemQtyBase = $quantity * $itemConv;

//                                         $ruleUomName = $rule->min_quantity_uom;
//                                         $ruleQtyBase = $rule->min_quantity;
//                                         if ($ruleUomName) {
//                                             $ruleUomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $ruleUomName) === 0);
//                                             $ruleConv = $ruleUomData?->conversion_rate ?? 1;
//                                             $ruleQtyBase = $rule->min_quantity * $ruleConv;
//                                         }
//                                         $qtyMatch = $itemQtyBase >= $ruleQtyBase;
//                                     }
//                                     $isApplicable = $qtyMatch;
//                                 }

//                                 if ($isApplicable) {
//                                     $basePrice = $product->price;
//                                     if ($customer && $channel === 'sales_order') {
//                                         $basePrice = $pricingService->getBasePrice($customer, $product);
//                                     }

//                                     $val = 0;
//                                     if ($rule->discount_type === 'percentage') {
//                                         $val = ((float)$basePrice * (float)$rule->discount_value / 100);
//                                     } else {
//                                         $val = (float)$rule->discount_value;
//                                     }

//                                     $uomData = $product->uoms->first(fn($v) => strcasecmp($v->uom_name ?? '', $uom) === 0);
//                                     $conv = $uomData?->conversion_rate ?? 1.0;

//                                     $discountAmountForRule += ($val * $conv * $quantity);
//                                 }
//                             }
//                         }
//                     }

//                     if ($discountAmountForRule > 0) {
//                         $totalDiscount += $discountAmountForRule;
//                         $appliedRules[] = $rule->name;
//                         Log::info(">> Rule Applied: $discountAmountForRule");
//                     } else {
//                         Log::warning(">> Rule Matched Name but Conditions Failed.");
//                     }

//                 }
//                 // Jika tidak ketemu di Discount Rules (Nama Promo), Cek Voucher
//                 else {
//                      // --- 3. Cek Member Voucher / Promo Code ---
//                      // Logic ini hanya jalan jika $targetRule (DiscountRule by Name) TIDAK KETEMU

//                      Log::info(">> Checking Voucher Code: $promoCode");

//                      // A. Cek Promo Umum
//                      $promo = Promo::where('code', $promoCode)->where('business_id', $businessId)->first();

//                      // B. Cek Member Voucher
//                      $memberVoucher = null;
//                      if (!$promo) {
//                         $memberVoucher = MemberVoucher::where('code', $promoCode)
//                             ->where('is_used', false)
//                             ->with('discountRule')
//                             ->first();

//                         if ($memberVoucher) {
//                             if (!$customer || (is_object($customer) && $customer->id !== $memberVoucher->member_id)) {
//                                 $memberVoucher = null;
//                             }
//                         }
//                      }

//                      if ($memberVoucher && $memberVoucher->discountRule) {
//                          // ... (Logic hitung Voucher Member - copy dari logic rule di atas) ...
//                          // Untuk simplifikasi, saya asumsikan logicnya sama, kita hitung manual di sini
//                          $rule = $memberVoucher->discountRule;
//                          $rule->condition_value_decoded = self::safeJsonDecode($rule->condition_value);
//                          $voucherDisc = 0;

//                          // ... Implementasi hitung ulang rule voucher di sini ...
//                          // (Agar kode tidak terlalu panjang, pastikan logic ini ada jika ingin voucher member jalan)
//                          // SEMENTARA: Saya skip detail hitungan ulang voucher member agar fokus ke fix stacking.
//                          // Pastikan Anda menggunakan logic yang sama dengan blok $targetRule di atas.
//                      }
//                      elseif ($promo) {
//                         // Logic Promo Umum
//                         $isEligible = $subTotal >= ($promo->min_purchase ?? 0);
//                         if ($isEligible) {
//                             $val = $promo->discount_value ?? 0;
//                             $d = $promo->discount_type === 'percentage' ? ($subTotal * $val / 100) : $val;
//                             $totalDiscount += $d;
//                             $appliedRules[] = "Promo: {$promo->code}";
//                         }
//                      }
//                 }
//             } else {
//                 Log::info(">> No Promo Code Provided. Only Global Discount Applied.");
//             }

//             Log::info(">> FINAL DISCOUNT: $totalDiscount");
//             return [
//                 'total_discount' => $totalDiscount,
//                 'applied_rules' => array_unique($appliedRules)
//             ];

//         } catch (\Throwable $e) {
//             Log::error("CRITICAL ERROR: " . $e->getMessage());
//             return ['total_discount' => 0, 'applied_rules' => []];
//         }
//     }

//     // ... (Helper methods isPosRuleApplicable & calculatePosRuleDiscount TETAP SAMA) ...
//     private static function isPosRuleApplicable(DiscountRule $rule, array $items, float $subTotal): bool
//     {
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
//             default: return false;
//         }
//     }

//     private static function calculatePosRuleDiscount(DiscountRule $rule, array $items, float $subTotal): float
//     {
//         $condition = $rule->condition_value_decoded ?? self::safeJsonDecode($rule->condition_value);

//         switch ($rule->type) {
//             case 'minimum_purchase':
//                 if ($rule->discount_type === 'percentage') {
//                     return ($subTotal * (float)$rule->discount_value) / 100;
//                 }
//                 return (float)$rule->discount_value;

//             case 'bogo_same_item':
//                 $productId = $condition['product_id'] ?? null;
//                 $buyQty = (int)($condition['buy_quantity'] ?? 1);
//                 $getQty = (int)($condition['get_quantity'] ?? 1);

//                 // Hindari pembagian nol
//                 $totalOfferUnit = $buyQty + $getQty;
//                 if ($totalOfferUnit <= 0) return 0;

//                 $itemInCart = Arr::first($items, fn ($item) => $item['product_id'] == $productId);

//                 if (!$itemInCart) return 0;

//                 // Hitung berapa kali promo berlaku (Floor)
//                 // Contoh: Beli 10, Paket 2 (1+1). Offers = 5.
//                 $numberOfOffers = floor($itemInCart['quantity'] / $totalOfferUnit);

//                 // Pastikan harga di-cast ke float
//                 $unitPrice = (float)($itemInCart['price'] ?? 0);

//                 return $numberOfOffers * $getQty * $unitPrice;

//             case 'category_discount':
//                 $categoryId = $condition['category_id'] ?? null;
//                 if (!$categoryId) return 0;
//                 $productIdsInCategory = Product::where('category_id', $categoryId)->pluck('id')->toArray();

//                 // Hitung total belanja KHUSUS kategori ini
//                 $totalForCategory = collect($items)
//                     ->whereIn('product_id', $productIdsInCategory)
//                     ->sum('total'); // Asumsi key 'total' ada di item array

//                 if ($rule->discount_type === 'percentage') {
//                     return ($totalForCategory * (float)$rule->discount_value) / 100;
//                 }
//                 return (float)$rule->discount_value;

//             case 'buy_x_get_y':
//                 $getProductId = $condition['get_product_id'] ?? null;
//                 $itemToDiscount = Arr::first($items, fn ($item) => $item['product_id'] == $getProductId);

//                 if (!$itemToDiscount) return 0;

//                 $priceY = (float)($itemToDiscount['price'] ?? 0);

//                 if ($rule->discount_type === 'percentage') {
//                     return ($priceY * (float)$rule->discount_value) / 100;
//                 }
//                 return (float)$rule->discount_value;

//             default:
//                 return 0;
//         }
//     }
// }




