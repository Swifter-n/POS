<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Ubah resource menjadi array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Muat relasi penting yang dibutuhkan Flutter
        $this->loadMissing('locationable', 'position', 'roles.permissions');

        return [
            // Data Dasar
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'nik' => $this->nik,
            'phone' => $this->phone,

            // Data Lokasi Kerja (Sangat penting untuk UI Flutter)
            // 'locationable_type' akan berisi misal: "App\Models\Warehouse"
            'location_type' => $this->locationable_type,
            'location_id' => $this->locationable_id,
            'location_name' => $this->locationable?->name, // misal: "Gudang Jakarta"

            // Data Jabatan
            'position' => $this->position?->name, // misal: "Staf Gudang"

            // Data Hak Akses (Sangat penting untuk menyembunyikan/menampilkan tombol)
            'roles' => $this->getRoleNames(), // misal: ["Staf Gudang", "Tim Kuning"]

            // Kirim semua hak akses yang dimiliki user
            'permissions' => $this->getAllPermissions()->pluck('name'),
        ];
    }
}
