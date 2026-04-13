<?php

namespace App\Http\Requests\API;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
         // Izinkan user membuat customer jika ia punya hak akses 'create'
        return $this->user()->can('create', Customer::class);
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
            'area_id' => 'required|exists:areas,id',
            'channel_id' => 'required|exists:channels,id',
            'sales_team_id' => 'nullable|exists:sales_teams,id',
            'price_list_id' => 'nullable|exists:price_lists,id',
            'status' => 'required|boolean',
            'contact_person' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers')->whereNull('deleted_at')],
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'village_id' => 'nullable|exists:villages,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'current_balance' => 'nullable|numeric|min:0', // Tetap validasi, tapi kita siapkan default-nya
        ];
    }

    protected function prepareForValidation(): void
    {
        // Jika 'credit_limit' atau 'current_balance' tidak dikirim dari frontend (kosong/null),
        // maka secara otomatis atur nilainya menjadi 0 sebelum divalidasi.
        $this->merge([
            'credit_limit' => $this->credit_limit ?? 0,
            'current_balance' => $this->current_balance ?? 0,
        ]);
    }
}
