<?php

namespace App\Policies;

use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ShippingRatePolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         //return $this->check($user, 'manage shipping rates');
         return true;
    }
    public function view(User $user, ShippingRate $r): bool
    {
        return true;
         //return $this->check($user, 'manage shipping rates') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage shipping rates');
    }
    public function update(User $user, ShippingRate $r): bool
    {
         return $this->check($user, 'manage shipping rates') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, ShippingRate $r): bool
    {
         return $this->check($user, 'manage shipping rates') && $user->business_id === $r->business_id;
    }
}
