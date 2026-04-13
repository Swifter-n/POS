<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class BusinessPolicy
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
        return $this->checkPermission($user, 'manage business settings');
    }

    public function view(User $user, Business $business): bool
    {
        return $this->checkPermission($user, 'manage business settings') && $user->business_id === $business->id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage business settings');
    }

    public function update(User $user, Business $business): bool
    {
        return $this->checkPermission($user, 'manage business settings') && $user->business_id === $business->id;
    }

    public function delete(User $user, Business $business): bool
    {
        return $this->checkPermission($user, 'manage business settings') && $user->business_id === $business->id;
    }
}
