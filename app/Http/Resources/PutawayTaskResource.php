<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PutawayTaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Mapping ke LocalTaskModel di Flutter
        return [
            'id' => $this->id,
            'transfer_number' => $this->transfer_number,
            'source_location' => $this->sourceLocation->name ?? 'Unknown',
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            // is_synced diatur oleh Flutter, server tidak perlu kirim
        ];
    }
}
