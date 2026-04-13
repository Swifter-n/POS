<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PickingTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Tentukan nomor dokumen sumber berdasarkan tipe polimorfik
        $sourceDocNumber = 'N/A';
        if ($this->sourceable_type === 'App\Models\SalesOrder') {
            $sourceDocNumber = $this->sourceable->so_number ?? '-';
        } elseif ($this->sourceable_type === 'App\Models\StockTransfer') {
            $sourceDocNumber = $this->sourceable->transfer_number ?? '-';
        } else {
            // Fallback untuk ProductionOrder atau lainnya
             $sourceDocNumber = $this->sourceable->document_number ?? '-';
        }

        return [
            'id' => $this->id,
            'picking_number' => $this->picking_list_number,
            'source_doc' => $sourceDocNumber,
            'warehouse_name' => $this->warehouse->name ?? 'Unknown Warehouse',
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
