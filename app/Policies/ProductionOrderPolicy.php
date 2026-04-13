<?php

namespace App\Policies;

use App\Models\ProductionOrder;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductionOrderPolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'view production orders');
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'create production orders');
    }
    public function complete(User $user, ProductionOrder $r): bool
    {
         return $this->check($user, 'complete production orders') && $user->business_id === $r->business_id;
    }
}
