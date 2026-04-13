<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class ProductPolicy
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
        //return $this->checkPermission($user, 'manage products');
    }

    public function view(User $user, Product $product): bool
    {
        return true;
        //return $this->checkPermission($user, 'manage products') && $user->business_id === $product->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage products');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->checkPermission($user, 'manage products') && $user->business_id === $product->business_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->checkPermission($user, 'manage products') && $user->business_id === $product->business_id;
    }
}
