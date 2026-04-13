<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChannelPolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage channels');
    }
    public function view(User $user, Channel $r): bool
    {
         return $this->check($user, 'manage channels') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage channels');
    }
    public function update(User $user, Channel $r): bool
    {
         return $this->check($user, 'manage channels') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Channel $r): bool
    {
         return $this->check($user, 'manage channels') && $user->business_id === $r->business_id;
    }
}
