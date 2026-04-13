<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'count_number' => $this->count_number,
            'status' => $this->status,
            'location_name' => $this->countable->name ?? 'Unknown Location',
            'plant_name' => $this->plant->name ?? '-',
            'created_by' => $this->createdBy->name ?? '-',
            'created_at' => $this->created_at->toIso8601String(),
            'notes' => $this->notes,
            // Total item yang harus dihitung
            'total_items' => $this->items_count,
            // Progress: Item yang sudah diisi / Total
            'counted_items' => $this->items()->whereNotNull('final_counted_stock')->count(),
        ];
    }
}
