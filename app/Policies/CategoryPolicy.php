<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
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
        return $this->checkPermission($user, 'manage categories');
    }

    public function view(User $user, Category $category): bool
    {
        return $this->checkPermission($user, 'manage categories') && $user->business_id === $category->business_id;
    }

    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage categories');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->checkPermission($user, 'manage categories') && $user->business_id === $category->business_id;
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->checkPermission($user, 'manage categories') && $user->business_id === $category->business_id;
    }
}
