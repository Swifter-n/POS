<?php

namespace App\Notifications;

use App\Models\MemberVoucher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class LoyaltyVoucherNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $voucher;
    public $campaignType;

    public function __construct(MemberVoucher $voucher, string $campaignType)
    {
        $this->voucher = $voucher;
        $this->campaignType = $campaignType;
    }

    /**
     * Tentukan channel pengiriman.
     */
    public function via($notifiable): array
    {
        // 1. Database: Selalu simpan ke Inbox aplikasi
        $channels = ['database'];

        // 2. Email: Cek apakah member punya email
        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        // 3. FCM (Push Notification): Cek apakah member punya token HP
        // Asumsi Anda nanti menginstall package seperti 'laravel-notification-channels/fcm'
        if (!empty($notifiable->fcm_token)) {
             // $channels[] = \NotificationChannels\Fcm\FcmChannel::class; // Uncomment jika driver sudah siap
        }

        return $channels;
    }

    /**
     * Format Email
     */
    public function toMail($notifiable): MailMessage
    {
        $voucherCode = $this->voucher->code;
        $promoName = $this->voucher->discountRule->name;
        $validUntil = $this->voucher->valid_until?->format('d M Y');

        $greeting = match ($this->campaignType) {
            'Birthday' => "Selamat Ulang Tahun, {$notifiable->name}! 🎂",
            'WinBack' => "Kami Rindu Kamu, {$notifiable->name}! 👋",
            default => "Halo, {$notifiable->name}!",
        };

        $line1 = match ($this->campaignType) {
            'Birthday' => "Sebagai kado spesial, kami punya hadiah untukmu.",
            'WinBack' => "Sudah lama tidak mampir nih. Yuk ngopi lagi!",
            default => "Kamu mendapatkan voucher baru.",
        };

        return (new MailMessage)
            ->subject("Hadiah Spesial: $promoName")
            ->greeting($greeting)
            ->line($line1)
            ->line("Gunakan kode voucher ini di kasir:")
            ->action($voucherCode, url('/'))
            ->line("Berlaku hingga: $validUntil")
            ->line('Terima kasih telah menjadi pelanggan setia kami!');
    }

    /**
     * Format Database (Inbox App)
     */
    public function toArray($notifiable): array
    {
        return [
            'title' => $this->campaignType === 'Birthday' ? 'Happy Birthday! 🎁' : 'Voucher Spesial Untukmu 🎟️',
            'body' => "Kamu dapat voucher {$this->voucher->discountRule->name}. Kode: {$this->voucher->code}",
            'voucher_id' => $this->voucher->id,
            'type' => 'voucher_issued',
            'campaign' => $this->campaignType,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * [CUSTOM] Logika Kirim WhatsApp
     */
    public static function sendWhatsApp($member, $voucher, $campaignType)
    {
        if (empty($member->phone)) return;

        $message = "";
        if ($campaignType == 'Birthday') {
            $message = "Happy Birthday {$member->name}! 🎂\n\n";
            $message .= "Kado spesial buat kamu: Voucher {$voucher->discountRule->name}.\n";
            $message .= "Kode: {$voucher->code}\n";
            $message .= "Yuk tukarkan sebelum expired!";
        } else {
            $message = "Halo {$member->name}, kami kangen nih! 👋\n\n";
            $message .= "Ada voucher {$voucher->discountRule->name} khusus buat kamu.\n";
            $message .= "Kode: {$voucher->code}";
        }

        try {
            // CONTOH PANGGILAN API WA (Ganti URL sesuai provider)
            /*
            Http::withHeaders([
                'Authorization' => 'TOKEN_WA_ANDA',
            ])->post('https://api.whatsapp.provider/send', [
                'target' => $member->phone,
                'message' => $message,
            ]);
            */

            // Simulasi kirim WA
            Log::info("WA Sent to {$member->phone}: $message");
        } catch (\Exception $e) {
            Log::error("Gagal kirim WA: " . $e->getMessage());
        }
    }

    /**
     * [OPTIONAL] Format untuk FCM (Jika nanti dipasang)
     */
    // public function toFcm($notifiable)
    // {
    //      return FcmMessage::create()
    //          ->setData(['voucher_code' => $this->voucher->code])
    //          ->setNotification(\NotificationChannels\Fcm\Resources\Notification::create()
    //              ->setTitle('Hadiah Voucher Baru!')
    //              ->setBody('Cek inbox kamu sekarang.'));
    // }
}



// class LoyaltyVoucherNotification extends Notification implements ShouldQueue
// {
//     use Queueable;

//     public $voucher;
//     public $campaignType; // 'Birthday', 'WinBack', 'Welcome'

//     public function __construct(MemberVoucher $voucher, string $campaignType)
//     {
//         $this->voucher = $voucher;
//         $this->campaignType = $campaignType;
//     }

//     /**
//      * Tentukan channel pengiriman.
//      * Kita bisa cek apakah user punya email/fcm_token di sini.
//      */
//     public function via($notifiable): array
//     {
//         $channels = ['database']; // Default: Simpan ke DB untuk notifikasi di App

//         // Jika member punya email, kirim email
//         if (!empty($notifiable->email)) {
//             $channels[] = 'mail';
//         }

//         // Channel WhatsApp (Custom)
//         // Kita panggil manual di method terpisah atau buat Custom Channel
//         // Untuk simplifikasi, kita akan panggil logic WA langsung di 'toWhatsApp' nanti
//         // tapi Laravel butuh driver khusus.
//         // Di sini kita simulasi panggil WA di dalam logic command atau job terpisah.

//         return $channels;
//     }

//     /**
//      * Format Email
//      */
//     public function toMail($notifiable): MailMessage
//     {
//         $voucherCode = $this->voucher->code;
//         $promoName = $this->voucher->discountRule->name;
//         $validUntil = $this->voucher->valid_until?->format('d M Y');

//         $greeting = match ($this->campaignType) {
//             'Birthday' => "Selamat Ulang Tahun, {$notifiable->name}! 🎂",
//             'WinBack' => "Kami Rindu Kamu, {$notifiable->name}! 👋",
//             default => "Halo, {$notifiable->name}!",
//         };

//         $line1 = match ($this->campaignType) {
//             'Birthday' => "Sebagai kado spesial, kami punya hadiah untukmu.",
//             'WinBack' => "Sudah lama tidak mampir nih. Yuk ngopi lagi!",
//             default => "Kamu mendapatkan voucher baru.",
//         };

//         return (new MailMessage)
//             ->subject("Hadiah Spesial: $promoName")
//             ->greeting($greeting)
//             ->line($line1)
//             ->line("Gunakan kode voucher ini di kasir:")
//             ->action($voucherCode, url('/')) // Link ke aplikasi/website
//             ->line("Berlaku hingga: $validUntil")
//             ->line('Terima kasih telah menjadi pelanggan setia kami!');
//     }

//     /**
//      * Format Database (Untuk Push Notif / List Notifikasi di App)
//      */
//     public function toArray($notifiable): array
//     {
//         return [
//             'title' => $this->campaignType === 'Birthday' ? 'Happy Birthday! 🎁' : 'Voucher Spesial Untukmu 🎟️',
//             'body' => "Kamu dapat voucher {$this->voucher->discountRule->name}. Kode: {$this->voucher->code}",
//             'voucher_id' => $this->voucher->id,
//             'type' => 'voucher_issued',
//             'campaign' => $this->campaignType,
//         ];
//     }

//     /**
//      * [CUSTOM] Logika Kirim WhatsApp
//      * Panggil function ini dari Command/Job
//      */
//     public static function sendWhatsApp($member, $voucher, $campaignType)
//     {
//         if (empty($member->phone)) return;

//         // Contoh integrasi dengan penyedia WA Gateway (misal: Fonnte, Twilio, Watzap)
//         // Sesuaikan URL dan Token dengan vendor yang Anda pakai.

//         $message = "";
//         if ($campaignType == 'Birthday') {
//             $message = "Happy Birthday {$member->name}! 🎂\n\n";
//             $message .= "Kado spesial buat kamu: Voucher *{$voucher->discountRule->name}*.\n";
//             $message .= "Kode: *{$voucher->code}*\n";
//             $message .= "Yuk tukarkan sebelum expired!";
//         } else {
//             $message = "Halo {$member->name}, kami kangen nih! 👋\n\n";
//             $message .= "Ada voucher *{$voucher->discountRule->name}* khusus buat kamu.\n";
//             $message .= "Kode: *{$voucher->code}*";
//         }

//         try {
//             // CONTOH PANGGILAN API WA (Ganti URL sesuai provider)
//             /*
//             Http::withHeaders([
//                 'Authorization' => 'TOKEN_WA_ANDA',
//             ])->post('https://api.whatsapp.provider/send', [
//                 'target' => $member->phone,
//                 'message' => $message,
//             ]);
//             */

//             Log::info("WA Sent to {$member->phone}: $message");

//         } catch (\Exception $e) {
//             Log::error("Gagal kirim WA: " . $e->getMessage());
//         }
//     }
// }
