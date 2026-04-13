<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity_change' => $this->quantity,
            'current_stock' => $this->current_stock,
            'type' => $this->type,
            'note' => $this->note,
            'created_at' => $this->created_at->format('d M Y, H:i'),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
