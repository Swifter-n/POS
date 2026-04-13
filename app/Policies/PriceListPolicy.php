<?php

namespace App\Policies;

use App\Models\PriceList;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PriceListPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage price lists');
    }
    public function view(User $user, PriceList $r): bool
    {
         return $this->check($user, 'manage price lists') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage price lists');
    }
    public function update(User $user, PriceList $r): bool
    {
         return $this->check($user, 'manage price lists') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, PriceList $r): bool
    {
         return $this->check($user, 'manage price lists') && $user->business_id === $r->business_id;
    }
}
