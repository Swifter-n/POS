<?php

namespace App\Policies;

use App\Models\SalesTeam;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalesTeamPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage sales teams');
    }
    public function view(User $user, SalesTeam $r): bool
    {
         return $this->check($user, 'manage sales teams') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage sales teams');
    }
    public function update(User $user, SalesTeam $r): bool
    {
         return $this->check($user, 'manage sales teams') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, SalesTeam $r): bool
    {
         return $this->check($user, 'manage sales teams') && $user->business_id === $r->business_id;
    }
}
