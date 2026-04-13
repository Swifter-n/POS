<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage customers');
    }
    public function view(User $user, Customer $r): bool
    {
         return $this->check($user, 'manage customers') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage customers');
    }
    public function update(User $user, Customer $r): bool
    {
         return $this->check($user, 'manage customers') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Customer $r): bool
    {
         return $this->check($user, 'manage customers') && $user->business_id === $r->business_id;
    }
}
