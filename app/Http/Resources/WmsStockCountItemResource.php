<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class WmsStockCountItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = Auth::user();

        // Ambil parent Task (StockCount)
        $task = $this->stockCount;

        // 1. Tentukan Role Validator
        // Validator = Owner, Manager, atau Tim Putih (White)
        $isValidator = $user->hasRole('Owner') ||
                       $user->hasRole('Manager Gudang') ||
                       in_array((string)$user->id, $task->assigned_teams['white'] ?? []) ||
                       in_array($user->id, $task->assigned_teams['white'] ?? []);

        // 2. Ambil Data Entry (Hitungan)
        // $this->entries sudah di-eager load oleh Controller
        $userEntry = $this->entries->where('user_id', $user->id)->first();

        // Ambil hitungan tim lain (Hanya untuk Validator)
        // Kita filter collection di memory (tidak query DB lagi)
        $yellowEntry = $this->entries->where('team_name', 'Kuning')->first();
        $greenEntry = $this->entries->where('team_name', 'Hijau')->first();

        return [
            'id' => $this->id,
            'stock_count_id' => $this->stock_count_id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'product_sku' => $this->product->sku,
            'product_barcode' => $this->product->barcode,
            'uom' => $this->product->base_uom,

            // --- DATA VALIDATOR (KUNCI PERBAIKAN) ---
            // Hanya kirim jika Validator, selain itu null (Blind Count)

            'system_stock' => $isValidator ? (float) $this->system_stock : null,

            // Gunakan 0 jika belum ada entry, agar UI menampilkan "0" bukan "-"
            'yellow_qty' => $isValidator ? ($yellowEntry ? (float)$yellowEntry->counted_quantity : 0.0) : null,
            'green_qty' => $isValidator ? ($greenEntry ? (float)$greenEntry->counted_quantity : 0.0) : null,

            'final_qty' => $this->final_counted_stock !== null ? (float)$this->final_counted_stock : null,

            'is_validator_view' => $isValidator, // Flag untuk Mobile UI
            // ----------------------------------------

            // Data Personal (Untuk Counter melanjutkan pekerjaan)
            'my_counted_qty' => $userEntry ? (float)$userEntry->counted_quantity : null,
            'my_team' => $userEntry ? $userEntry->team_name : null,
            'is_zero_count' => $userEntry ? (bool)$userEntry->is_zero_count : false,

            // Info Lokasi
            'batch' => $this->inventory->batch ?? null,
            'location_name' => $this->inventory->location->name ?? null,
        ];
    }
}
