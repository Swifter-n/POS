<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockCountPolicy
{
    use HandlesAuthorization, HasPermissionChecks;
    public function viewAny(User $user): bool
    {
         return $this->check($user, 'view stock counts');
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'create stock counts');
    }
    public function start(User $user, StockCount $r): bool
    {
         return $this->check($user, 'start stock counts');
    }
    public function submit(User $user, StockCount $r): bool
    {
         return $this->check($user, 'submit stock counts');
    }
    public function post(User $user, StockCount $r): bool
    {
         return $this->check($user, 'post stock count adjustments');
    }
}
