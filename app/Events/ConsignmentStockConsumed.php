<?php

namespace App\Events;

use App\Models\Inventory;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConsignmentStockConsumed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Instance dari batch inventaris yang dikonsumsi.
     * Mengandung informasi produk, batch, sled, dan lokasi.
     *
     * @var \App\Models\Inventory
     */
    public $inventory;

    /**
     * Kuantitas yang dikonsumsi dari batch ini (dalam Base UoM).
     *
     * @var int
     */
    public $consumedQuantity;

    /**
     * Dokumen yang memicu konsumsi ini (bisa berupa SalesOrder, Order, StockCount, dll).
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    public $triggeringRecord;

    /**
     * Buat instance event baru.
     *
     * @param \App\Models\Inventory $inventory
     * @param int $consumedQuantity
     * @param \Illuminate\Database\Eloquent\Model $triggeringRecord
     */
    public function __construct(Inventory $inventory, int $consumedQuantity, Model $triggeringRecord)
    {
        $this->inventory = $inventory;
        $this->consumedQuantity = $consumedQuantity;
        $this->triggeringRecord = $triggeringRecord;
    }
}

