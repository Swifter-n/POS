<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PutawayItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Hitung qty yang sudah di-scan (moved)
        $qtyScanned = $this->putAwayEntries->sum('quantity_moved');

        // Mapping ke LocalItemModel di Flutter
        return [
            'id' => $this->id,
            'task_id' => $this->stock_transfer_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'product_barcode' => $this->product->barcode, // Atau $this->product->barcode
            'qty_requested' => (float) $this->quantity, // Base UoM
            'qty_scanned' => (float) $qtyScanned,       // Base UoM
            'uom' => $this->uom, // Base UoM
            //'suggested_bin' => $this->suggestedLocation->name ?? null,
            'suggested_bin' => $this->suggestedLocation ? $this->suggestedLocation->name : null,

            // Ini akan null jika dari PO, tapi terisi jika dari STO (Traceability)
            'batch' => $this->batch, // <-- Direct Access ke kolom tabel
            'sled' => $this->sled,   // <-- Direct Access ke kolom tabel

            'scanned_bin' => null, // Diisi oleh mobile
        ];
    }
}
