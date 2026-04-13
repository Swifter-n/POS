<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PickingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Siapkan Map Konversi: ['CRT' => 24, 'BOX' => 10, 'PCS' => 1]
        $conversionRates = $this->product->uoms->pluck('conversion_rate', 'uom_name')->toArray();
        // Pastikan Base UoM selalu ada dengan rate 1
        $conversionRates[$this->product->base_uom] = 1;
        return [
            'id' => $this->id,
            'picking_list_id' => $this->picking_list_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'product_sku' => $this->product->sku,
            'product_barcode' => $this->product->barcode, // Barcode untuk scan validasi produk

            'qty_to_pick' => (float) $this->total_quantity_to_pick,
            'qty_picked' => (float) ($this->quantity_picked ?? 0),
            'uom' => $this->uom, // Base UoM
            'conversion_rates' => $conversionRates,

            // Nested Resource: Instruksi detail (Batch & Lokasi)
            'instructions' => PickingSourceResource::collection($this->sources),
        ];
    }
}
