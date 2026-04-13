<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarehousePolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
        return true;
        //return $this->check($user, 'manage warehouses');
    }
    public function view(User $user, Warehouse $r): bool
    {
        return true;
         //return $this->check($user, 'manage warehouses') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
        return $this->check($user, 'manage warehouses');
    }
    public function update(User $user, Warehouse $r): bool
    {
        return $this->check($user, 'manage warehouses') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Warehouse $r): bool
    {
         return $this->check($user, 'manage warehouses') && $user->business_id === $r->business_id;
    }

}
