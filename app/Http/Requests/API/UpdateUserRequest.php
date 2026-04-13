<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ambil user yang akan diupdate dari parameter rute
        $userToUpdate = $this->route('user');
        return $this->user()->can('update', $userToUpdate);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;
        return [
            'nik' => 'sometimes|string|max:25',
            'name' => 'sometimes|string|max:255',
            // Pastikan email unik, kecuali untuk user yang sedang diedit
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'phone' => 'sometimes|string|max:20',
            'password' => 'nullable|string|min:6',
            'role_id' => ['sometimes', 'integer', Rule::in([2, 3])], // Hanya boleh update menjadi manajer/staf
            'outlet_id' => 'sometimes|exists:outlets,id',
            'status' => 'sometimes|boolean',
        ];
    }
}
