<?php

namespace App\Policies;

use App\Models\DiscountRule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DiscountRulePolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage discount rules');
    }
    public function view(User $user, DiscountRule $r): bool
    {
         return $this->check($user, 'manage discount rules') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage discount rules');
    }
    public function update(User $user, DiscountRule $r): bool
    {
         return $this->check($user, 'manage discount rules') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, DiscountRule $r): bool
    {
         return $this->check($user, 'manage discount rules') && $user->business_id === $r->business_id;
    }
}
