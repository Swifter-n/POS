<?php

namespace App\Services;

use App\Models\Member;
use App\Models\DiscountRule;
use App\Models\MemberVoucher;
use Illuminate\Support\Str;
use Carbon\Carbon;

class VoucherService
{
    /**
     * Memberikan voucher ke member berdasarkan Discount Rule.
     * @param Member $member Member yang akan diberi voucher
     * @param DiscountRule $rule Template diskon yang digunakan
     * @param int|null $validDays Masa berlaku dalam hari (opsional).
     */
    // === PERBAIKAN: Tambahkan '?' sebelum 'int' ===
    public static function issueVoucher(Member $member, DiscountRule $rule, ?int $validDays = null)
    {
        // 1. Generate Kode Unik
        $prefix = Str::slug($rule->name);
        $prefix = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix)), 0, 4);

        do {
            $code = $prefix . '-' . $member->id . '-' . Str::upper(Str::random(4));
        } while (MemberVoucher::where('code', $code)->exists());

        // 2. Tentukan Valid Until
        $validUntil = null;

        if ($validDays) {
            $validUntil = now()->addDays($validDays);
        } elseif ($rule->valid_to) {
            $validUntil = $rule->valid_to;
        }

        // 3. Create Voucher Instance
        return MemberVoucher::create([
            'member_id' => $member->id,
            'discount_rule_id' => $rule->id,
            'code' => $code,
            'is_used' => false,
            'valid_from' => now(),
            'valid_until' => $validUntil,
        ]);
    }
}
