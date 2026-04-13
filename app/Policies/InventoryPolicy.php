<?php

namespace App\Policies;

use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryPolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'view inventory');
    }
}
