<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class StockCountItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_count_id' => $this->stock_count_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'product_sku' => $this->product->sku,
            'product_barcode' => $this->product->barcode,
            'uom' => $this->product->base_uom, // Base UoM

            // System Stock (Snapshot).
            // Di POS biasanya ditampilkan agar kasir tau stok komputer berapa.
            'system_stock' => (float) $this->system_stock,

            // Hasil hitungan user (bisa null jika belum dihitung)
            // Di POS langsung tembak ke final_counted_stock
            'counted_qty' => $this->final_counted_stock !== null ? (float) $this->final_counted_stock : null,
            'is_zero_count' => (bool) $this->is_zero_count,

            // Batch info (Opsional untuk POS, biasanya POS jarang pakai batch kecuali F&B expired)
            'batch' => $this->inventory->batch ?? null,
            'location_name' => $this->inventory->location->name ?? null,
        ];
    }
}

// class StockCountItemResource extends JsonResource
// {
//     public function toArray(Request $request): array
//     {
//         return [
//             'id' => $this->id,
//             'stock_count_id' => $this->stock_count_id,
//             'product_id' => $this->product_id,
//             'product_name' => $this->product->name,
//             'product_sku' => $this->product->sku,
//             'product_barcode' => $this->product->barcode,
//             'uom' => $this->product->base_uom, // Base UoM

//             // System Stock (Snapshot).
//             // Mobile app bisa memilih untuk menyembunyikan ini (Blind Count) atau menampilkannya.
//             'system_stock' => (float) $this->system_stock,

//             // Hasil hitungan user (bisa null jika belum dihitung)
//             'counted_qty' => $this->final_counted_stock !== null ? (float) $this->final_counted_stock : null,
//             'is_zero_count' => (bool) $this->is_zero_count,

//             // Batch info jika inventory support batch
//             'batch' => $this->inventory->batch ?? null,
//             'location_name' => $this->inventory->location->name ?? null,
//         ];
//     }
// }




