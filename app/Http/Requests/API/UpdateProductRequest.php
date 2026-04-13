<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ambil produk dari parameter rute dan cek hak akses 'update'
        $product = $this->route('product');
        return $this->user()->can('update', $product);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Dapatkan ID produk dari rute untuk aturan validasi 'unique'
        $productId = $this->route('product')->id;

        // 'sometimes' berarti hanya validasi jika field tersebut dikirim
        return [
            'name' => 'sometimes|string|max:50',
            // Pastikan SKU unik, kecuali untuk produk yang sedang di-update
            'sku' => ['sometimes', 'string', 'max:6', Rule::unique('products')->ignore($productId)],
            'material_code' => 'sometimes|string|max:25',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'sometimes|exists:categories,id',
            'brand_id' => 'sometimes|exists:brands,id',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'price' => 'sometimes|numeric|min:0',
            'cost' => 'sometimes|numeric|min:0',
            'barcode' => 'sometimes|string|max:255',
            'color' => 'nullable|string|max:255',
            'status' => 'sometimes|boolean',
            'is_popular' => 'sometimes|boolean',
            'is_promo' => 'sometimes|boolean',
            'percent' => 'nullable|numeric|required_if:is_promo,true|min:0|max:100',
        ];
    }
}
