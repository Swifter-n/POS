<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PositionPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage positions');
    }
    public function view(User $user, Position $r): bool
    {
         return $this->check($user, 'manage positions') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage positions');
    }
    public function update(User $user, Position $r): bool
    {
         return $this->check($user, 'manage positions') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Position $r): bool
    {
         return $this->check($user, 'manage positions') && $user->business_id === $r->business_id;
    }
}
