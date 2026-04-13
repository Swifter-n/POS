<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\DB;

class AreaPolicy
{
use HandlesAuthorization;
    private function userHasRole(User $user, string $roleName): bool
{
    // Implementasi pengecekan role...
    // Contoh: return $user->roles()->where('name', $roleName)->exists();
    // Atau gunakan query DB seperti di Resource Anda
     return DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)->where('model_id', $user->id)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', $roleName)
            ->exists();
}

private function check(User $user, string $p): bool
{
     // TAMBAHKAN BYPASS INI
     if ($this->userHasRole($user, 'Owner')) {
         return true;
     }

     // Logika asli Anda
     return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
}

    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage areas');
    }
    public function view(User $user, Area $r): bool
    {
         return $this->check($user, 'manage areas') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage areas');
    }
    public function update(User $user, Area $r): bool
    {
         return $this->check($user, 'manage areas') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Area $r): bool
    {
         return $this->check($user, 'manage areas') && $user->business_id === $r->business_id;
    }
}
