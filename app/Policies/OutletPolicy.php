<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class OutletPolicy
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
        //return $this->checkPermission($user, 'manage outlets');
    }

    public function view(User $user, Outlet $outlet): bool
    {
        return true;
        //return $this->checkPermission($user, 'manage outlets') && $user->business_id === $outlet->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage outlets');
    }

    public function update(User $user, Outlet $outlet): bool
    {
        return $this->checkPermission($user, 'manage outlets') && $user->business_id === $outlet->business_id;
    }

    public function delete(User $user, Outlet $outlet): bool
    {
        return $this->checkPermission($user, 'manage outlets') && $user->business_id === $outlet->business_id;
    }
}
