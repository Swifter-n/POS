<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InvoicePolicy
{
    use HandlesAuthorization;
    private function check(User $user, string $p): bool
    {
         return $user->roles()->whereHas('permissions', fn($q) => $q->where('name', $p))->exists();
    }
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'manage invoices');
    }
    public function view(User $user, Invoice $r): bool
    {
         return $this->check($user, 'manage invoices') && $user->business_id === $r->business_id;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'manage invoices');
    }
    public function update(User $user, Invoice $r): bool
    {
         return $this->check($user, 'manage invoices') && $user->business_id === $r->business_id;
    }
    public function delete(User $user, Invoice $r): bool
    {
         return $this->check($user, 'manage invoices') && $user->business_id === $r->business_id;
    }
}
