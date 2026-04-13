<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\Product::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'material_code' => 'required|string|max:25',
            'sku' => 'required|string|max:6',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'required|exists:brands,id',
            'thumbnail' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'required|numeric|min:0',
            'cost' => 'required|numeric|min:0',
            'barcode' => 'required|string|max:255',
            'color' => 'nullable|string|max:255',
            'status' => 'required|boolean',
            'is_popular' => 'required|boolean',
            'is_promo' => 'required|boolean',
            'percent' => 'nullable|numeric|required_if:is_promo,true|min:0|max:100',

            // Relasi (data array)
            'productphotos' => 'nullable|array',
            'productphotos.*.photo' => 'required|image|max:2048', // Validasi file di dalam array

            'productsizes' => 'nullable|array',
            'productsizes.*.size' => 'required|string|max:255',

            'productIngredients' => 'nullable|array',
            'productIngredients.*.name' => 'required|string|max:255',
        ];
    }
}
