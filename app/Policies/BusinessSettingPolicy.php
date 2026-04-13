<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class BusinessSettingPolicy
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

    /**
     * Perform pre-authorization checks.
     * Owner bisa melakukan apa saja.
     */
    public function before(User $user, string $ability): bool|null
    {
        // Gunakan pengecekan relasi langsung, bukan hasRole()
        if ($user->roles->contains('name', 'Owner')) {
            return true;
        }
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->checkPermission($user, 'manage business settings');
    }

    public function view(User $user, BusinessSetting $businessSetting): bool
    {
        return $this->checkPermission($user, 'manage business settings') && $user->business_id === $businessSetting->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage business settings');
    }

    public function update(User $user, BusinessSetting $businessSetting): bool
    {
        return $this->checkPermission($user, 'manage business settings') && $user->business_id === $businessSetting->business_id;
    }

    public function delete(User $user, BusinessSetting $businessSetting): bool
    {
        return $this->checkPermission($user, 'manage business settings') && $user->business_id === $businessSetting->business_id;
    }
}
