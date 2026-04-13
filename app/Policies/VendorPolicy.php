<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\HandlesAuthorization;

class VendorPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage vendors');
    }
    public function view(User $user, Vendor $r): bool
    {
         return $this->check($user, 'manage vendors') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage vendors');
    }
    public function update(User $user, Vendor $r): bool
    {
         return $this->check($user, 'manage vendors') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Vendor $r): bool
    {
         return $this->check($user, 'manage vendors') && $user->business_id === $r->business_id;
    }
}
