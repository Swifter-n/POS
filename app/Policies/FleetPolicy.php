<?php

namespace App\Policies;

use App\Models\Fleet;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FleetPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage fleets');
    }
    public function view(User $user, Fleet $r): bool
    {
         return $this->check($user, 'manage fleets') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage fleets');
    }
    public function update(User $user, Fleet $r): bool
    {
         return $this->check($user, 'manage fleets') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Fleet $r): bool
    {
         return $this->check($user, 'manage fleets') && $user->business_id === $r->business_id;
    }
}
