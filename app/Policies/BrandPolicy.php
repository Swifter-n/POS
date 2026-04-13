<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class BrandPolicy
{
    use HandlesAuthorization, HasPermissionChecks;

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
        return $this->checkPermission($user, 'manage brands');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->checkPermission($user, 'manage brands') && $user->business_id === $brand->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage brands');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->checkPermission($user, 'manage brands') && $user->business_id === $brand->business_id;
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->checkPermission($user, 'manage brands') && $user->business_id === $brand->business_id;
    }
}
