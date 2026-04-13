<?php

namespace App\Http\Requests\API;

use App\Models\Outlet;
use Illuminate\Foundation\Http\FormRequest;

class StoreOutletRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ambil 'business' dari parameter rute, contoh: /businesses/1/outlets
        $business = $this->route('business');
        return $this->user()->can('create', [Outlet::class, $business]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'nullable|string',
            'status' => 'sometimes|boolean',
        ];
    }
}
