<?php

namespace App\Policies;

use App\Models\GoodsReturn;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class GoodsReturnPolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'view goods return');
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'create goods return');
    }
    public function approve(User $user, GoodsReturn $r): bool
    {
         return $this->check($user, 'approve goods return');
    }
    public function cancel(User $user, GoodsReturn $r): bool
    {
         return $this->check($user, 'cancel goods return');
    }

}
