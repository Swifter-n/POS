<?php

namespace App\Policies;

use App\Models\DebitNote;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DebitNotePolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage debit notes');
    }
    public function view(User $user, DebitNote $r): bool
    {
         return $this->check($user, 'manage debit notes') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage debit notes');
    }
    public function update(User $user, DebitNote $r): bool
    {
         return $this->check($user, 'manage debit notes') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, DebitNote $r): bool
    {
         return $this->check($user, 'manage debit notes') && $user->business_id === $r->business_id;
    }
}
