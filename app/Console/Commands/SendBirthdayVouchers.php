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

class SendBirthdayVouchers extends Command
{
    protected $signature = 'loyalty:send-birthday-vouchers';
    protected $description = 'Kirim voucher ke member yang ulang tahun hari ini';

    public function handle()
    {
        $today = Carbon::now();
        $this->info("Memulai proses Birthday Voucher untuk tanggal: " . $today->toDateString());

        $settings = BusinessSetting::where('type', 'birthday_voucher_rule')
            ->where('status', true)
            ->get();

        foreach ($settings as $setting) {
            $businessId = $setting->business_id;
            $ruleId = $setting->value;

            $rule = DiscountRule::find($ruleId);
            if (!$rule || !$rule->is_active) continue;

            $validitySetting = BusinessSetting::where('business_id', $businessId)
                ->where('type', 'birthday_voucher_days')->first();
            $validDays = $validitySetting ? (int)$validitySetting->value : 30;

            $birthdayMembers = Member::where('business_id', $businessId)
                ->whereMonth('dob', $today->month)
                ->whereDay('dob', $today->day)
                ->get();

            $count = 0;
            foreach ($birthdayMembers as $member) {
                $alreadyIssued = MemberVoucher::where('member_id', $member->id)
                    ->where('discount_rule_id', $rule->id)
                    ->whereYear('created_at', $today->year)
                    ->exists();

                if (!$alreadyIssued) {
                    $voucher = VoucherService::issueVoucher($member, $rule, $validDays);

                    // === KIRIM NOTIFIKASI (Email & App) ===
                    $member->notify(new LoyaltyVoucherNotification($voucher, 'Birthday'));

                    // === KIRIM WHATSAPP ===
                    LoyaltyVoucherNotification::sendWhatsApp($member, $voucher, 'Birthday');

                    $count++;
                    $this->info("Voucher Ultah dikirim ke: {$member->name}");
                }
            }

            $this->info("Business ID $businessId: $count voucher terkirim.");
        }
    }
}
