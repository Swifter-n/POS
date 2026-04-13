<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseReturnPolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'view purchase return');
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'create purchase return');
    }
    public function approve(User $user, PurchaseReturn $r): bool
    {
         return $this->check($user, 'approve purchase return');
    }
    public function cancel(User $user, PurchaseReturn $r): bool
    {
         return $this->check($user, 'cancel purchase return');
    }
}
