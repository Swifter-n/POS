<?php

namespace App\Console\Commands;

use App\Models\BusinessSetting;
use App\Models\DiscountRule;
use App\Models\Member;
use App\Models\MemberVoucher;
use App\Notifications\LoyaltyVoucherNotification;
use App\Services\VoucherService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;


class SendWinBackVouchers extends Command
{
    protected $signature = 'loyalty:send-winback-vouchers';
    protected $description = 'Kirim voucher ke member yang sudah lama tidak belanja (Win-Back)';

    public function handle()
    {
        $this->info("Memulai proses Win-Back Voucher...");

        $settings = BusinessSetting::where('type', 'winback_voucher_rule')
            ->where('status', true)
            ->get();

        foreach ($settings as $setting) {
            $businessId = $setting->business_id;
            $ruleId = $setting->value;

            $rule = DiscountRule::find($ruleId);
            if (!$rule || !$rule->is_active) continue;

            $thresholdSetting = BusinessSetting::where('business_id', $businessId)
                ->where('type', 'winback_threshold_days')->first();
            $daysInactive = $thresholdSetting ? (int)$thresholdSetting->value : 30;

            $validitySetting = BusinessSetting::where('business_id', $businessId)
                ->where('type', 'winback_voucher_validity')->first();
            $validDays = $validitySetting ? (int)$validitySetting->value : 14;

            $cutOffDate = Carbon::now()->subDays($daysInactive)->endOfDay();

            $targetMembers = Member::where('business_id', $businessId)
                ->where('last_transaction_at', '<=', $cutOffDate)
                ->get();

            $count = 0;
            foreach ($targetMembers as $member) {
                $alreadySentRecently = MemberVoucher::where('member_id', $member->id)
                    ->where('discount_rule_id', $rule->id)
                    ->where('created_at', '>=', Carbon::now()->subDays(90))
                    ->exists();

                if (!$alreadySentRecently) {
                    $voucher = VoucherService::issueVoucher($member, $rule, $validDays);

                    // === KIRIM NOTIFIKASI (Email & App) ===
                    $member->notify(new LoyaltyVoucherNotification($voucher, 'WinBack'));

                    // === KIRIM WHATSAPP ===
                    LoyaltyVoucherNotification::sendWhatsApp($member, $voucher, 'WinBack');

                    $count++;
                    $this->info("WinBack Voucher dikirim ke: {$member->name}");
                }
            }

            $this->info("Business ID $businessId: $count voucher WinBack terkirim.");
        }
    }
}
