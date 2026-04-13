<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LocationPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
        return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
        return true;
         //return $this->check($user, 'manage locations');
    }
    public function view(User $user, Location $r): bool
    {
        return true;
         //return $this->check($user, 'manage locations') && $user->business_id === $r->locatable->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage locations');
    }
    public function update(User $user, Location $r): bool
    {
         return $this->check($user, 'manage locations') && $user->business_id === $r->locatable->business_id;
    }
    public function delete(User $user, Location $r): bool
    {
         return $this->check($user, 'manage locations') && $user->business_id === $r->locatable->business_id;
    }
}
