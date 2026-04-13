<?php

namespace App\Policies;

use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class InventoryAdjustmentPolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    public function create(User $user): bool
    {
         return $this->check($user, 'adjust inventory');
    }

}
