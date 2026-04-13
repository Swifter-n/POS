<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Traits\HasPermissionChecks;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockTransferPolicy
{
    use HandlesAuthorization, HasPermissionChecks;

    public function view(User $user, StockTransfer $stockTransfer): bool
{
    // PAKSA UNTUK TESTING
    return true;
}
    public function viewAny(User $user): bool
    {
         //return $this->check($user, 'view stock transfers');
         return true;
    }
    public function create(User $user): bool
    {
         return $this->check($user, 'create stock transfers');
    }
    public function approve(User $user, StockTransfer $r): bool
    {
         return $this->check($user, 'approve stock transfers') && $user->business_id === $r->business_id;
    }
    public function executeInternal(User $user, StockTransfer $r): bool
    {
         return $this->check($user, 'execute internal transfers') && $user->business_id === $r->business_id;
    }
}
