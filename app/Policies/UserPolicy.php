<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Helper function untuk memeriksa permission secara manual.
     */
    private function checkPermission(User $user, string $permissionName): bool
    {
        // Cek langsungß ke relasi roles, lalu ke relasi permissions di dalam role
        return $user->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    public function viewAny(User $user): bool
    {
        return $this->checkPermission($user, 'manage users');
    }

    public function view(User $user, User $targetUser): bool
    {
        // User harus punya izin DAN target user harus berada di bisnis yang sama
        return $this->checkPermission($user, 'manage users') && $user->business_id === $targetUser->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage users');
    }

    public function update(User $user, User $targetUser): bool
    {
        // User harus punya izin DAN target user harus berada di bisnis yang sama
        return $this->checkPermission($user, 'manage users') && $user->business_id === $targetUser->business_id;
    }

    public function delete(User $user, User $targetUser): bool
    {
        // User harus punya izin DAN target user harus berada di bisnis yang sama
        return $this->checkPermission($user, 'manage users') && $user->business_id === $targetUser->business_id;
    }
}
