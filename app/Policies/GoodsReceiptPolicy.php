<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class GoodsReceiptPolicy
{
    use HandlesAuthorization;

    /**
     * Helper function untuk memeriksa permission secara manual.
     */
    private function checkPermission(User $user, string $permissionName): bool
    {
        // Cek langsung ke relasi roles, lalu ke relasi permissions di dalam role
        return $user->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    public function viewAny(User $user): bool
    {
        return true;
        //return $this->checkPermission($user, 'view goods receipts');
    }

    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return true;
        //return $this->checkPermission($user, 'view goods receipts') && $user->business_id === $goodsReceipt->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'receive goods');
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return false; // Tetap false, GR tidak seharusnya diubah
    }

    public function delete(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return false; // Tetap false, GR tidak seharusnya dihapus
    }
}
