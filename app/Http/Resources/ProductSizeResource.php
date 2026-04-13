<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSizeResource extends JsonResource
{
   /**
     * Ubah resource menjadi array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'base_uom' => $this->base_uom,
            'thumbnail' => $this->thumbnail, // Nanti tambahkan URL lengkap jika perlu

            // Harga final (sudah memperhitungkan PriceList)
            // 'final_price' HANYA ada jika Anda menggunakan query 'join'
            'price' => (float) $this->final_price,

            'product_type' => $this->product_type,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,

            // Sertakan semua UoM yang bisa dijual
            'uoms' => $this->whenLoaded('uoms', function() {
                return $this->uoms->where('uom_type', 'selling')
                    ->map(fn($uom) => [
                        'uom_name' => $uom->uom_name,
                        'conversion_rate' => (float) $uom->conversion_rate,
                    ])->values(); // 'values()' untuk reset keys
            }),
        ];
    }
}
