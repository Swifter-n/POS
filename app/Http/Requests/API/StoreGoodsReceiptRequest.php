<?php

namespace App\Http\Requests\API;

use App\Models\GoodsReceipt;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', GoodsReceipt::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'receipt_date' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_received' => 'required|integer|min:0',
            'items.*.batch' => 'nullable|string|max:255',
            'items.*.sled' => 'nullable|date',
            'items.*.location_id' => 'nullable|exists:locations,id',
            'items.*.notes' => 'nullable|string',
        ];
    }
}
