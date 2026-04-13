<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// --- IMPORT PACKAGE FCM ---
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use NotificationChannels\Fcm\Resources\AndroidConfig;
use NotificationChannels\Fcm\Resources\AndroidNotification;
// --------------------------

class TaskAssignedNotification extends Notification
{
    use Queueable;

    public $taskType;
    public $taskNumber;
    public $taskId;

    /**
     * Create a new notification instance.
     */
    public function __construct($taskType, $taskNumber, $taskId)
    {
        $this->taskType = $taskType;
        $this->taskNumber = $taskNumber;
        $this->taskId = $taskId;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    /**
     * Get the array representation of the notification (Database).
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Tugas {$this->taskType} Baru",
            'body' => "Anda telah diberikan tugas {$this->taskType} nomor {$this->taskNumber}.",
            'type' => strtolower($this->taskType),
            'reference_id' => $this->taskId,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get the FCM representation of the notification.
     * PERBAIKAN: Menggunakan method data(), notification(), android()
     */
    public function toFcm($notifiable)
    {
        return FcmMessage::create()
            // [FIX] Ganti setData() menjadi data()
            ->data([
                'type' => strtolower($this->taskType), // putaway
                'reference_id' => (string) $this->taskId,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'sound' => 'default',
            ])
            // [FIX] Ganti setNotification() menjadi notification()
            ->notification(
                FcmNotification::create()
                    ->title("Tugas {$this->taskType} Baru")
                    ->body("Task #{$this->taskNumber} telah ditugaskan kepada Anda.")
            );
            // [FIX] Ganti setAndroid() menjadi android()
            // ->android(
            //     AndroidConfig::create()
            //         ->notification(
            //             AndroidNotification::create()
            //                 ->color('#0A0A0A')
            //                 ->clickAction('FLUTTER_NOTIFICATION_CLICK')
            //                 ->sound('default')
            //         )
            // );
    }
}
