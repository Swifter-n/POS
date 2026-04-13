<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrderPolicy
{
    use HandlesAuthorization, HasPermissionChecks;

    private function check(User $user, string $permissionName): bool
    {
        // Cek langsung ke relasi roles, lalu ke relasi permissions di dalam role
        return $user->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    public function create(User $user): bool
    {
         return $this->check($user, 'create pos orders');
    }

    /**
     * Tentukan apakah user bisa meng-update order.
     */
    public function update(User $user, Order $order): bool
    {
        // ==========================================================
        // --- PERBAIKAN: Izinkan edit untuk SEMUA status 'terbuka' ---
        // ==========================================================
        // Samakan dengan logika 'visible' di tombol Anda
        $isEditableStatus = in_array(strtolower($order->status), ['pending', 'open', 'unpaid']);

        return $isEditableStatus && $this->check($user, 'create pos orders');
    }

    // Anda mungkin juga perlu menambahkan method 'viewAny' dan 'view'
    // jika user Anda tidak bisa melihat halaman list/view sama sekali.

    public function viewAny(User $user): bool
    {
        // Asumsi: Jika bisa buat, bisa lihat
         return $this->check($user, 'create pos orders');
    }

    public function view(User $user, Order $order): bool
    {
        // Asumsi: Jika bisa buat, bisa lihat
         return $this->check($user, 'create pos orders');
    }

    public function delete(User $user, Order $order): bool
    {
        // Asumsi: Hanya boleh hapus jika masih draft/pending
        $isEditableStatus = in_array(strtolower($order->status), ['pending', 'open', 'unpaid']);

        return $isEditableStatus && $this->check($user, 'create pos orders'); // Atau permission 'delete pos orders'
    }
}
