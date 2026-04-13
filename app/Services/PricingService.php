<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

class PricingService
{
    /**
     * Menghitung harga jual final, harga dasar, dan diskon untuk SATU item.
     *
     * @param Customer $customer
     * @param Product $product
     * @param int $quantity
     * @param string $uom UoM dari item yang diorder (misal: "PCS", "BOX")
     * @return array [
     * 'base_price' => (float) harga dasar per PCS,
     * 'discount_amount' => (float) total diskon per PCS,
     * 'final_price' => (float) harga final per PCS
     * ]
     */
    public function calculateItemPricing(Customer $customer, Product $product, int $quantity, string $uom): array
    {
        // 1. Ambil harga dasar (base price) per PCS dari hierarki price list
        $basePrice = $this->getBasePrice($customer, $product);

        // 1b. Muat relasi UoM pada produk. Ini penting untuk Celah 3
        $product->loadMissing('uoms');

        // 2. Ambil aturan diskon yang relevan (logika Anda sebelumnya sudah benar)
        $activeRules = DiscountRule::where('is_active', true)
            ->where('business_id', Auth::user()->business_id)
            ->whereIn('applicable_for', ['sales_order', 'all'])
            ->where(fn($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->orderBy('priority', 'asc')
            ->get();

        $totalDiscountAmount = 0;

        // 3. Terapkan aturan diskon yang cocok
        foreach ($activeRules as $rule) {

            // --- PERUBAHAN: Kirim $uom ke method isRuleApplicable ---
            if ($this->isRuleApplicable($rule, $customer, $product, $quantity, $uom)) {

                $discountValue = 0;
                if ($rule->discount_type === 'percentage') {
                    // Diskon dihitung dari harga dasar
                    $discountValue = ($basePrice * $rule->discount_value / 100);
                } else { // fixed_amount
                    // Diskon tetap per PCS (base UoM)
                    $discountValue = $rule->discount_value;
                }

                $totalDiscountAmount += $discountValue;

                // Jika aturan ini eksklusif, hentikan
                if (!$rule->is_cumulative) {
                    break;
                }
            }
        }

        // 4. Hitung harga final
        $finalPrice = $basePrice - $totalDiscountAmount;
        $finalPrice = $finalPrice > 0 ? $finalPrice : 0; // Pastikan tidak negatif

        // 5. Kembalikan array lengkap
        return [
            'base_price' => $basePrice,
            'discount_amount' => $totalDiscountAmount,
            'final_price' => $finalPrice,
        ];
    }

    /**
     * Mendapatkan harga dasar berdasarkan hierarki prioritas (Price List).
     *
     * --- PERUBAHAN: Diubah dari 'private' ke 'public' ---
     * Agar bisa diakses oleh DiscountService.
     */
    public function getBasePrice(Customer $customer, Product $product): float
    {
        // ==========================================================
        // --- PERBAIKAN: Gunakan 'priorityLevel' (ONO/ONA) untuk HARGA ---
        // ==========================================================
        // Prioritas 1: Cek Price List dari Priority Level customer
        $priorityLevel = $customer->priorityLevel; // Asumsi relasi 'priorityLevel' ada
        if ($priorityLevel && $priorityLevel->price_list_id) { // Asumsi PriorityLevel punya price_list_id
            $priceItem = PriceListItem::where('price_list_id', $priorityLevel->price_list_id)
                ->where('product_id', $product->id)
                ->first();
            if ($priceItem) return (float) $priceItem->price;
        }
        // ==========================================================

        // Prioritas 2: Cek Price List spesifik milik customer
        if ($customer->price_list_id) {
            $priceItem = PriceListItem::where('price_list_id', $customer->price_list_id)
                ->where('product_id', $product->id)
                ->first();
            if ($priceItem) return (float) $priceItem->price;
        }

        // Prioritas 3: Fallback ke harga dasar produk
        return (float) $product->price; // Asumsi 'price' adalah harga jual dasar
    }

    /**
     * Memeriksa apakah sebuah aturan diskon berlaku untuk kondisi Sales Order.
     *
     * @param string $uom UoM dari item yang diorder
     *
     * --- PERUBAHAN: Diubah dari 'private' ke 'public' ---
     * Agar bisa diakses oleh DiscountService.
     */
    public function isRuleApplicable(DiscountRule $rule, Customer $customer, Product $product, int $quantity, string $uom): bool
    {
        // Asumsi model Customer punya channel_id

        // --- PERBAIKAN (CELAH 1) ---
        // Cek field 'customer_channel' (bukan customer_channel_id)
        if ($rule->customer_channel && $rule->customer_channel !== $customer->channel_id) return false;
        // --- AKHIR PERBAIKAN (CELAH 1) ---

        if ($rule->customer_id && $rule->customer_id !== $customer->id) return false;
        if ($rule->product_id && $rule->product_id !== $product->id) return false;
        if ($rule->brand_id && $rule->brand_id !== $product->brand_id) return false;

        // ==========================================================
        // --- PERBAIKAN: Bandingkan 'priority_level_id' dengan 'priorityLevel' ---
        // ==========================================================
        // Cek Priority Level (ONO/ONA)
        if ($rule->priority_level_id && $customer->priorityLevel?->id !== $customer->priorityLevel->id) return false;
        // ==========================================================


        // ==========================================================
        // --- START: Celah 3 (UoM Hilang) Fix ---
        // ==========================================================
        if ($rule->min_quantity && $rule->min_quantity > 0) {

            // 1. Dapatkan UoM dari order (yang di-input user, misal: "BOX")
            //    Kita sudah memuat 'uoms' di method calculateItemPricing
            $orderUomData = $product->uoms->where('uom_name', $uom)->first();

            // Jika UoM order tidak ditemukan di produk (misal: 'KARTON' padahal di master 'BOX')
            // kita asumsikan 1. Ini skenario fallback.
            $orderConversionRate = $orderUomData->conversion_rate ?? 1.0;

            // 2. Dapatkan UoM dari aturan diskon (misal: "PCS")
            //    Jika aturan tidak menentukan UoM, kita ambil base_uom produk, atau fallback ke 'PCS'
            $ruleUomName = $rule->min_quantity_uom ?? $product->base_uom;
            if (empty($ruleUomName)) $ruleUomName = 'PCS'; // Fallback final jika base_uom juga null

            $ruleUomData = $product->uoms->where('uom_name', $ruleUomName)->first();

            // Jika UoM aturan ("PCS") tidak ada di master UoM produk, kita asumsikan 1
            $ruleConversionRate = $ruleUomData->conversion_rate ?? 1.0;

            // 3. Konversi kedua kuantitas ke Base UoM (standar terkecil)
            //    Contoh Order: $quantity = 2 (BOX), $orderConversionRate = 12
            //    -> $totalQuantityInBase = 2 * 12 = 24 (PCS)
            $totalQuantityInBase = $quantity * $orderConversionRate;

            //    Contoh Aturan: $rule->min_quantity = 10 (PCS), $ruleConversionRate = 1
            //    -> $requiredQuantityInBase = 10 * 1 = 10 (PCS)
            $requiredQuantityInBase = $rule->min_quantity * $ruleConversionRate;

            // 4. Bandingkan
            //    (Apakah 24 < 10?) -> False. Diskon BERLAKU.
            if ($totalQuantityInBase < $requiredQuantityInBase) {
                return false; // Kuantitas tidak mencukupi
            }
        }
        // ==========================================================
        // --- END: Celah 3 (UoM Hilang) Fix ---
        // ==========================================================

        return true;
    }
}
