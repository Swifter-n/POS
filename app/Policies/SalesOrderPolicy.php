<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalesOrderPolicy
{
    use HandlesAuthorization, HasPermissionChecks;


    public function view(User $user, SalesOrder $salesOrder): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return true;
        // return $this->check($user, 'view sales orders');
    }

    public function create(User $user): bool
    {
         return $this->check($user, 'create sales orders');
    }
    public function approve(User $user, SalesOrder $r): bool
    {
         return $this->check($user, 'approve sales orders');
    }
    public function cancel(User $user, SalesOrder $r): bool
    {
         return $this->check($user, 'cancel sales orders');
    }
}
