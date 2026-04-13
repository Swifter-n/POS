<?php

namespace App\Policies;

use App\Models\Promo;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PromoPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage promos');
    }
    public function view(User $user, Promo $r): bool
    {
         return $this->check($user, 'manage promos') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage promos');
    }
    public function update(User $user, Promo $r): bool
    {
         return $this->check($user, 'manage promos') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Promo $r): bool
    {
         return $this->check($user, 'manage promos') && $user->business_id === $r->business_id;
    }
}
