<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

class RolePolicy
{
    use HandlesAuthorization;

    /**
     * Helper function untuk memeriksa permission secara manual via query langsung.
     */
    private function checkPermission(User $user, string $permissionName): bool
    {
        if (!$user) return false;

        // Query langsung ke tabel Spatie untuk memeriksa hak akses
        return $user->roles()->whereHas('permissions', function ($query) use ($permissionName) {
            $query->where('name', $permissionName);
        })->exists();
    }

    /**
     * Tentukan apakah user bisa melihat DAFTAR role.
     * Ini mengontrol visibilitas menu di sidebar.
     */
    public function viewAny(User $user): bool
    {
        // Ganti 'view_any_role' dengan nama permission yang Anda definisikan di seeder,
        // misalnya 'manage roles'
        return $this->checkPermission($user, 'manage roles');
    }

    /**
     * Tentukan apakah user bisa melihat DETAIL role.
     */
    public function view(User $user, Role $role): bool
    {
        return $this->checkPermission($user, 'manage roles');
    }

    /**
     * Tentukan apakah user bisa MEMBUAT role baru.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'manage roles');
    }

    /**
     * Tentukan apakah user bisa MENGUPDATE role.
     */
    public function update(User $user, Role $role): bool
    {
        return $this->checkPermission($user, 'manage roles');
    }

    /**
     * Tentukan apakah user bisa MENGHAPUS role.
     */
    public function delete(User $user, Role $role): bool
    {
        return $this->checkPermission($user, 'manage roles');
    }
}
