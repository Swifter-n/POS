<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Tentukan apakah user diizinkan membuat request ini.
     */
    public function authorize(): bool
    {
        return true; // Siapapun boleh mencoba login
    }

    /**
     * Dapatkan aturan validasi yang berlaku untuk request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credential' => 'required|string', // Ini bisa email atau NIK
            'password' => 'required|string',
            'device_name' => 'required|string', // Wajib untuk mobile (misal: "Ibnu's iPhone 15")
        ];
    }
}
