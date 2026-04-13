<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\PurchaseReturn;

class PurchaseReturnCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    public $purchaseReturn;

    /**
     * Create a new event instance.
     */
    public function __construct(PurchaseReturn $purchaseReturn)
    {
        $this->purchaseReturn = $purchaseReturn;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}
