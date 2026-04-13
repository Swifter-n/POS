<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class PurchaseOrderPolicy
{
    use HandlesAuthorization;

    /**
     * Helper function untuk memeriksa permission secara manual.
     */
    private function check(User $user, string $permissionName): bool
    {
        // Cek langsung ke relasi roles, lalu ke relasi permissions di dalam role
        return $user->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

 public function viewAny(User $user): bool
{
    return true;
    //return $this->check($user, 'view purchase orders');
}

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return true;
        //return $this->check($user, 'view purchase orders') && $user->business_id === $purchaseOrder->business_id;
    }

    public function create(User $user): bool
    {
        return $this->check($user, 'create purchase orders');
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return true;
        //return $this->check($user, 'edit purchase orders') && $user->business_id === $purchaseOrder->business_id;
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->check($user, 'delete purchase orders') && $user->business_id === $purchaseOrder->business_id;
    }

    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->check($user, 'approve purchase orders') && $user->business_id === $purchaseOrder->business_id;
    }
}
