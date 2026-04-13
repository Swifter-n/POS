<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait HasPermissionChecks
{
   private function check(User $user, string $permissionName): bool
    {
        if (!$user) return false;

        // Panggil helper userHasRole (yang juga sudah diperbarui)
        if ($this->userHasRole($user, 'Owner')) {
            return true;
        }

        // Cek permission spesifik via query langsung
        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->where('model_id', $user->id)
            ->join('role_has_permissions', 'model_has_roles.role_id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->where('permissions.name', $permissionName)
            ->exists();
    }

    private function userHasRole(User $user, string $roleName): bool
    {
        if (!$user) return false;

        // Ganti dari '$user->roles->contains(...)' menjadi query langsung ke database.
        return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
    }

}
