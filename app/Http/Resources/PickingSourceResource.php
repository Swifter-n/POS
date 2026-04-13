<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PickingSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // return [
        //     'id' => $this->id, // ID PickingListItemSource
        //     'inventory_id' => $this->inventory_id,

        //     // Lokasi Target
        //     'location_id' => $this->inventory->location_id,
        //     'location_name' => $this->inventory->location->name ?? 'Unknown',
        //     'zone_code' => $this->inventory->location->zone->code ?? 'N/A',
        //     'location_code' => $this->inventory->location->code ?? $this->inventory->location->name ?? '-',

        //     // Batch Target (FEFO Instruction)
        //     'batch' => $this->inventory->batch,
        //     'sled' => $this->inventory->sled,

        //     // Jumlah yang harus diambil dari titik ini
        //     'qty_to_pick' => (float) $this->quantity_to_pick_from_source,
        // ];
        $product = $this->inventory->product;
        $qty = (float) $this->quantity_to_pick_from_source;
        $baseUom = $product->base_uom;

        $qtyDisplay = "$qty $baseUom"; // Default

        if ($product && $product->uoms->isNotEmpty()) {
            // Cari UoM terbesar yang conversion rate-nya > 1 (misal CRT=24)
            $largestUom = $product->uoms
                ->where('conversion_rate', '>', 1)
                ->sortByDesc('conversion_rate')
                ->first();

            if ($largestUom) {
                $rate = $largestUom->conversion_rate;
                $bigUnit = floor($qty / $rate);
                $remainder = fmod($qty, $rate); // Sisa bagi

                if ($bigUnit > 0) {
                    $qtyDisplay = "$bigUnit {$largestUom->uom_name}";
                    if ($remainder > 0) {
                        $qtyDisplay .= " + $remainder $baseUom";
                    }
                    // Opsional: Tambahkan total base di kurung
                    // $qtyDisplay .= " ($qty $baseUom)";
                }
            }
        }
        return [
            'id' => $this->id,
            'inventory_id' => $this->inventory_id,

            'location_id' => $this->inventory->location_id,
            'location_name' => $this->inventory->location->name ?? 'Unknown',

            // PERBAIKAN: Pastikan tidak null. Gunakan nama jika kode tidak ada.
            'location_code' => $this->inventory->location->code ?? $this->inventory->location->name ?? '-',

            'zone_code' => $this->inventory->location->zone->code ?? 'N/A',
            'batch' => $this->inventory->batch,
            'sled' => $this->inventory->sled,
            'qty_allocated' => $qty,
            'qty_display' => $qtyDisplay,
        ];
    }
}
