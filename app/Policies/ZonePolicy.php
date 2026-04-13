<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Zone;
use Illuminate\Auth\Access\HandlesAuthorization;

class ZonePolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage zones');
    }
    public function view(User $user, Zone $r): bool
    {
         return $this->check($user, 'manage zones') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage zones');
    }
    public function update(User $user, Zone $r): bool
    {
         return $this->check($user, 'manage zones') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Zone $r): bool
    {
         return $this->check($user, 'manage zones') && $user->business_id === $r->business_id;
    }
}
