<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Otomatis cek hak akses menggunakan Policy sebelum validasi
        return $this->user()->can('create', \App\Models\BusinessSetting::class);
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
            'charge_type' => 'required|string|in:fixed,percent', // Pastikan nilainya valid
            'type' => 'required|string|max:255',
            'value' => 'required|string|max:255',
            'status' => 'sometimes|boolean',
        ];
    }
}
