<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Ubah resource menjadi array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', $this->product?->name),
            'calories' => $this->whenLoaded('product', $this->product?->calories),
            'quantity' => (float) $this->quantity,
            'uom' => $this->uom,
            'price_per_uom' => (float) $this->price, // Harga per UoM
            'total_price' => (float) $this->total,
            'note' => $this->note,
            'addons' => $this->whenLoaded('addons', function () {
                return $this->addons->map(function ($addon) {
                    return [
                        'id' => $addon->id,
                        'addon_id' => $addon->addon_product_id,
                        'name' => $addon->addonProduct?->name,
                        'quantity' => (float) $addon->quantity,
                        'price' => (float) $addon->price,
                        'total' => (float) $addon->total,
                    ];
                });
            }),
        ];
    }
}
