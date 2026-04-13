<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
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
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'price' => $this->price,
            'price_afterdiscount' => $this->price_afterdiscount,
            'color' => $this->color,
            'thumbnail_url' => $this->thumbnail ? Storage::url($this->thumbnail) : null,
            'status' => $this->status,
            'is_popular' => $this->is_popular,
            'is_promo' => $this->is_promo,
            'percent_promo' => $this->percent,

            // Muat relasi jika diminta
            'category' => new CategoryResource($this->whenLoaded('category')),
            'brand' => new BrandResource($this->whenLoaded('brand')),
            //'stocks' => StockResource::collection($this->whenLoaded('stocks')),
            'sizes' => ProductSizeResource::collection($this->whenLoaded('productsizes')),
            'photos' => ProductPhotoResource::collection($this->whenLoaded('productphotos')),
            'ingredients' => ProductIngredientResource::collection($this->whenLoaded('productIngredients')),
        ];
    }
}
