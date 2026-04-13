<?php

namespace App\Policies;

use App\Models\PriorityLevel;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PriorityLevelPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage priority levels');
    }
    public function view(User $user, PriorityLevel $r): bool
    {
         return $this->check($user, 'manage priority levels') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage priority levels');
    }
    public function update(User $user, PriorityLevel $r): bool
    {
         return $this->check($user, 'manage priority levels') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, PriorityLevel $r): bool
    {
         return $this->check($user, 'manage priority levels') && $user->business_id === $r->business_id;
    }
}
